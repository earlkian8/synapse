<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// ── Listing ─────────────────────────────────────────────────────────────────

test('the employees index renders', function () {
    actingAsSuperAdmin();
    Employee::factory()->count(3)->create();

    $this->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employees/index')
            ->has('employees.data', 3)
            ->has('stats')
            ->has('options')
            ->has('can')
            ->has('filters'));
});

test('it filters employees by employment status', function () {
    actingAsSuperAdmin();
    Employee::factory()->create(['employment_status' => 'active']);
    Employee::factory()->create(['employment_status' => 'resigned']);

    $this->get(route('employees.index', ['status' => 'resigned']))
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));
});

test('it filters employees by department', function () {
    actingAsSuperAdmin();
    $dept = Department::factory()->create();
    Employee::factory()->create(['department_id' => $dept->id]);
    Employee::factory()->create();

    $this->get(route('employees.index', ['department' => $dept->id]))
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));
});

test('it searches employees', function () {
    actingAsSuperAdmin();
    Employee::factory()->create(['first_name' => 'Zorroberto', 'last_name' => 'Uniqueson']);

    $this->get(route('employees.index', ['search' => 'Zorroberto']))
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));
});

// ── Mutations ───────────────────────────────────────────────────────────────

test('it creates an employee and auto-generates the employee number', function () {
    actingAsSuperAdmin();

    $this->post(route('employees.store'), [
        'first_name' => 'New',
        'last_name' => 'Hire',
        'employment_type' => 'probationary',
        'employment_status' => 'active',
        'date_hired' => '2026-01-15',
    ])->assertSessionHasNoErrors();

    $employee = Employee::where('last_name', 'Hire')->first();

    expect($employee)->not->toBeNull()
        ->and($employee->employee_no)->toStartWith('EMP-');
});

test('it validates required fields when creating', function () {
    actingAsSuperAdmin();

    $this->post(route('employees.store'), [])
        ->assertSessionHasErrors(['first_name', 'last_name', 'date_hired']);
});

test('it records a promotion when the position changes', function () {
    actingAsSuperAdmin();
    $from = Position::factory()->create();
    $to = Position::factory()->create();
    $employee = Employee::factory()->create([
        'position_id' => $from->id,
        'basic_salary' => 30000,
    ]);

    $this->post(route('employees.update', $employee), [
        'first_name' => $employee->first_name,
        'last_name' => $employee->last_name,
        'employment_type' => $employee->employment_type,
        'employment_status' => $employee->employment_status,
        'date_hired' => $employee->date_hired->toDateString(),
        'position_id' => $to->id,
        'basic_salary' => 45000,
    ])->assertSessionHasNoErrors();

    expect($employee->promotions()->count())->toBe(1)
        ->and($employee->promotions()->first()->to_position_id)->toBe($to->id);
});

test('an employee cannot be their own manager', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();

    $this->post(route('employees.update', $employee), [
        'first_name' => $employee->first_name,
        'last_name' => $employee->last_name,
        'employment_type' => $employee->employment_type,
        'employment_status' => $employee->employment_status,
        'date_hired' => $employee->date_hired->toDateString(),
        'manager_id' => $employee->id,
    ])->assertSessionHasErrors('manager_id');
});

test('it archives, restores and force-deletes an employee', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();

    $this->delete(route('employees.destroy', $employee))->assertSessionHasNoErrors();
    expect(Employee::find($employee->id))->toBeNull();

    $this->patch(route('employees.restore', $employee->id))->assertSessionHasNoErrors();
    expect(Employee::find($employee->id))->not->toBeNull();

    $this->delete(route('employees.force-delete', $employee->id))->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
});

test('it quick-sets the employment status', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create(['employment_status' => 'active']);

    $this->patch(route('employees.status', $employee), ['employment_status' => 'suspended'])
        ->assertSessionHasNoErrors();

    expect($employee->fresh()->employment_status)->toBe('suspended');
});

test('it exports employees as a csv download', function () {
    actingAsSuperAdmin();
    Employee::factory()->create(['first_name' => 'Exportable']);

    $response = $this->get(route('employees.export'));

    $response->assertOk();
    $response->assertDownload();
    expect($response->streamedContent())->toContain('Exportable');
});

// ── Bulk ────────────────────────────────────────────────────────────────────

test('it bulk archives employees', function () {
    actingAsSuperAdmin();
    $a = Employee::factory()->create();
    $b = Employee::factory()->create();

    $this->post(route('employees.bulk'), [
        'action' => 'archive',
        'ids' => [$a->id, $b->id],
    ])->assertSessionHasNoErrors();

    expect(Employee::count())->toBe(0);
});

test('it bulk sets status', function () {
    actingAsSuperAdmin();
    $a = Employee::factory()->create(['employment_status' => 'active']);
    $b = Employee::factory()->create(['employment_status' => 'active']);

    $this->post(route('employees.bulk'), [
        'action' => 'set-status',
        'ids' => [$a->id, $b->id],
        'status' => 'on_leave',
    ])->assertSessionHasNoErrors();

    expect($a->fresh()->employment_status)->toBe('on_leave')
        ->and($b->fresh()->employment_status)->toBe('on_leave');
});

// ── Documents & certifications ───────────────────────────────────────────────

test('it uploads and removes an employee document', function () {
    Storage::fake('public');
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();

    $this->post(route('employees.documents.store', $employee), [
        'title' => 'Contract',
        'type' => 'contract',
        'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = $employee->documents()->first();
    expect($document)->not->toBeNull();
    Storage::disk('public')->assertExists($document->file);

    $this->delete(route('employees.documents.destroy', [$employee, $document]))
        ->assertSessionHasNoErrors();
    expect($employee->documents()->count())->toBe(0);
});

test('it records and removes a certification', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();

    $this->post(route('employees.certifications.store', $employee), [
        'name' => 'PMP',
        'issuer' => 'PMI',
    ])->assertSessionHasNoErrors();

    $cert = $employee->certifications()->first();
    expect($cert)->not->toBeNull();

    $this->delete(route('employees.certifications.destroy', [$employee, $cert]))
        ->assertSessionHasNoErrors();
    expect($employee->certifications()->count())->toBe(0);
});

// ── Authorization ────────────────────────────────────────────────────────────

test('a user without employees.view is denied the index', function () {
    actingAsUserWith(['users.view']);

    $this->get(route('employees.index'))->assertForbidden();
});

test('a user without employees.create cannot create', function () {
    actingAsUserWith(['employees.view']);

    $this->post(route('employees.store'), [
        'first_name' => 'No',
        'last_name' => 'Access',
        'employment_type' => 'probationary',
        'employment_status' => 'active',
        'date_hired' => '2026-01-01',
    ])->assertForbidden();
});

test('a user without employees.force-delete cannot bulk delete', function () {
    actingAsUserWith(['employees.view']);
    $employee = Employee::factory()->create();
    $employee->delete();

    $this->post(route('employees.bulk'), [
        'action' => 'delete',
        'ids' => [$employee->id],
    ])->assertForbidden();

    $this->assertDatabaseHas('employees', ['id' => $employee->id]);
});

test('a user without employees.manage-documents cannot upload documents', function () {
    actingAsUserWith(['employees.view']);
    $employee = Employee::factory()->create();

    $this->post(route('employees.documents.store', $employee), [
        'title' => 'X',
        'type' => 'other',
        'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
    ])->assertForbidden();
});

// ── User link ────────────────────────────────────────────────────────────────

test('an employee can be linked to a user, and the link is unique', function () {
    actingAsSuperAdmin();
    $user = User::factory()->create();
    Employee::factory()->create(['user_id' => $user->id]);

    $this->post(route('employees.store'), [
        'first_name' => 'Dupe',
        'last_name' => 'Link',
        'employment_type' => 'probationary',
        'employment_status' => 'active',
        'date_hired' => '2026-01-01',
        'user_id' => $user->id,
    ])->assertSessionHasErrors('user_id');
});

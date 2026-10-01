<?php

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

// ── Isolation ─────────────────────────────────────────────────────────────────

test('the employee directory only shows the current organisation', function () {
    actingAsSuperAdmin();                       // binds tenant A
    Employee::factory()->count(2)->create();    // 2 employees in A

    $orgB = Organization::factory()->create();
    app(Tenancy::class)->runFor($orgB, fn () => Employee::factory()->count(5)->create());

    expect(Employee::withoutGlobalScopes()->count())->toBe(7);

    $this->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 2));
});

test('an employee from another organisation cannot be viewed', function () {
    actingAsSuperAdmin();

    $orgB = Organization::factory()->create();
    $foreign = app(Tenancy::class)->runFor($orgB, fn () => Employee::factory()->create());

    $this->getJson(route('employees.show', $foreign->id))->assertNotFound();
});

test('roles are scoped per organisation', function () {
    actingAsSuperAdmin();                        // org A gets a super-admin role

    $orgB = Organization::factory()->create();
    app(Tenancy::class)->runFor($orgB, fn () => Role::create([
        'name' => Role::SUPER_ADMIN,
        'label' => 'Super Admin',
        'is_system' => true,
    ]));

    // Same machine name in two organisations is allowed by the composite unique.
    expect(Role::withoutGlobalScopes()->where('name', Role::SUPER_ADMIN)->count())->toBe(2)
        ->and(Role::count())->toBe(1); // current tenant (A) sees only its own
});

// ── Registration provisions a tenant ──────────────────────────────────────────

test('registration provisions an organisation owned by the registrant', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->post(route('register.store'), [
        'organization_name' => 'Globex',
        'first_name' => 'Hank',
        'last_name' => 'Scorpio',
        'email' => 'hank@globex.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $org = Organization::where('name', 'Globex')->first();
    expect($org)->not->toBeNull();

    // The registrant is a global identity who becomes a member — and owner — of the
    // organisation they created (ADR 0023).
    $user = User::where('email', 'hank@globex.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->isMemberOf($org))->toBeTrue();

    app(Tenancy::class)->runFor($org, function () use ($user) {
        expect($user->fresh()->isSuperAdmin())->toBeTrue();
    });

    // Every new tenant gets the full built-in role set: HR Manager (the owner),
    // Department Head, Staff. See OrganizationProvisioner::roleBlueprints().
    $roles = Role::withoutGlobalScopes()->where('organization_id', $org->id)->pluck('name');

    expect($roles)->toHaveCount(3)
        ->and($roles->all())->toEqualCanonicalizing([
            Role::HR_MANAGER, Role::DEPARTMENT_HEAD, Role::STAFF,
        ]);
});

// ── Per-tenant numbering ──────────────────────────────────────────────────────

test('employee numbers start fresh in a new organisation', function () {
    actingAsSuperAdmin();

    $this->post(route('employees.store'), [
        'first_name' => 'First',
        'last_name' => 'Hire',
        'employment_type' => 'probationary',
        'employment_status' => 'active',
        'date_hired' => '2026-01-15',
    ])->assertSessionHasNoErrors();

    expect(Employee::first()->employee_no)->toBe('EMP-00001');
});

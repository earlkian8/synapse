<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\User;
use App\Services\Assistant\Modules\EmployeeRecordsModule;
use App\Services\Assistant\ToolResult;

/*
| The employee-records capability (ADR 0059): certifications — read, found by
| expiry, added and removed through the profile's own path — and a person's
| document list, which needs the documents permission and is audited.
*/

function recordsAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(EmployeeRecordsModule::class)->run($user, $tool, $args);
}

function licensed(string $first, string $last): Employee
{
    return Employee::factory()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null]);
}

test('certifications are added, found by expiry and removed — each recorded', function () {
    $user = actingAsSuperAdmin();
    $nora = licensed('Nora', 'Nurse');
    $ben = licensed('Ben', 'Builder');
    EmployeeCertification::create(['employee_id' => $ben->id, 'name' => 'Safety Officer 2', 'expiry_date' => now()->addDays(200)->toDateString()]);

    $added = recordsAgent($user, 'add_certification', ['employee' => 'Nora Nurse', 'name' => 'PRC Nursing License', 'issuer' => 'PRC', 'expiry_date' => now()->addDays(30)->toDateString()]);
    $twice = recordsAgent($user, 'add_certification', ['employee' => 'Nora Nurse', 'name' => 'prc nursing license']);
    $badDate = recordsAgent($user, 'add_certification', ['employee' => 'Nora Nurse', 'name' => 'BLS', 'expiry_date' => 'next year']);
    $soon = collect(recordsAgent($user, 'find_certifications', ['expiring_within_days' => 60])->cards)->pluck('title')->all();
    $removed = recordsAgent($user, 'remove_certification', ['employee' => 'Nora Nurse', 'certification' => 'nursing']);

    expect($added->failed())->toBeFalse()
        ->and($twice->detail)->toContain('already has a certification called')
        ->and($badDate->detail)->toContain('is not a date')
        ->and($soon)->toBe(['PRC Nursing License'])
        ->and(app(EmployeeRecordsModule::class)->requiresConfirmation('remove_certification'))->toBeTrue()
        ->and($removed->label)->toBe('Removed “PRC Nursing License” from Nora Nurse')
        ->and($nora->certifications()->count())->toBe(0)
        ->and(ActivityLog::query()->where('description', 'Removed certification "PRC Nursing License" from Nora Nurse via assistant')->exists())->toBeTrue();
});

test('removing a certification on the profile is recorded too', function () {
    actingAsSuperAdmin();
    $nora = licensed('Nora', 'Nurse');
    $certification = EmployeeCertification::create(['employee_id' => $nora->id, 'name' => 'BLS']);

    $this->delete(route('employees.certifications.destroy', [$nora, $certification]))->assertSessionHasNoErrors();

    expect(ActivityLog::query()->where('description', 'Removed certification "BLS" from Nora Nurse')->exists())->toBeTrue();
});

test('a document list needs the documents permission, and reading it is audited', function () {
    $viewer = actingAsUserWith(['employees.view']);
    $nora = licensed('Nora', 'Nurse');
    $nora->documents()->create(['title' => 'Signed contract', 'type' => 'contract', 'file' => 'x.pdf']);
    $module = app(EmployeeRecordsModule::class);

    expect(array_column($module->tools($viewer), 'name'))->toBe(['find_certifications'])
        ->and(recordsAgent($viewer, 'find_employee_documents', ['employee' => 'Nora Nurse'])->detail)->toContain("don't have permission");

    $keeper = actingAsUserWith(['employees.view', 'employees.manage-documents']);
    $docs = recordsAgent($keeper, 'find_employee_documents', ['employee' => 'Nora Nurse']);

    expect($docs->cards[0]['title'])->toBe('Signed contract')
        ->and(json_encode($docs->cards))->not->toContain('x.pdf')
        ->and(ActivityLog::query()->where('event', 'viewed')->where('description', 'Viewed the document list of Nora Nurse via assistant')->exists())->toBeTrue();
});

test('an employee in the trash is neither listed nor counted', function () {
    $user = actingAsSuperAdmin();
    $gone = licensed('Gus', 'Gone');
    EmployeeCertification::create(['employee_id' => $gone->id, 'name' => 'First Aid', 'expiry_date' => now()->addDays(20)->toDateString()]);
    $gone->delete();

    $soon = recordsAgent($user, 'find_certifications', ['expiring_within_days' => 60]);

    expect($soon->cards)->toBe([])
        ->and($soon->detail)->toBe('None')
        ->and(app(EmployeeRecordsModule::class)->topicContext($user)->toPrompt())->toContain('0 certifications expire in the next 90 days');
});

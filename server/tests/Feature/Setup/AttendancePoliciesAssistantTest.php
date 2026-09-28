<?php

use App\Models\ActivityLog;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Assistant\Modules\AttendancePoliciesModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\ScheduleAssigner;
use App\Support\Tenancy;

/*
| The attendance-policy capability of the assistant: how a day is judged,
| explained, tried on a sample day and changed by the Attendance Policies
| screen's own rules — and never the capture controls. Gemini is never called.
*/

beforeEach(function () {
    $this->travelTo('2026-09-28 10:00:00');
});

function policiesAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(AttendancePoliciesModule::class)->run($user, $tool, $args);
}

function policiesAgentTools(User $user): array
{
    return array_column(app(AttendancePoliciesModule::class)->tools($user), 'name');
}

/** A policy in the current test tenant, from settings laid over the fallback. */
function policyNamed(string $name, array $settings = [], bool $default = false): AttendancePolicy
{
    return AttendancePolicy::create([
        'name' => $name,
        'settings' => AttendancePolicySettings::fromArray($settings)->toArray(),
        'settings_version' => AttendancePolicySettings::VERSION,
        'is_default' => $default,
    ]);
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('a viewer reads; who is judged by what also needs the directory; a manager changes', function () {
    expect(policiesAgentTools(actingAsUserWith(['setup.attendance-policies.view'])))
        ->toEqualCanonicalizing(['find_attendance_policies', 'get_attendance_policy', 'get_worked_example'])
        ->and(policiesAgentTools(actingAsUserWith(['setup.attendance-policies.view', 'employees.view'])))
        ->toContain('get_applicable_policy');

    $tools = policiesAgentTools(actingAsUserWith(['setup.attendance-policies.view', 'setup.attendance-policies.manage']));

    expect($tools)->toContain('create_attendance_policy', 'update_attendance_policy', 'set_default_attendance_policy', 'archive_attendance_policy')
        ->not->toContain('delete_attendance_policy');

    $module = app(AttendancePoliciesModule::class);

    expect($module->requiresConfirmation('update_attendance_policy'))->toBeTrue()
        ->and($module->requiresConfirmation('set_default_attendance_policy'))->toBeTrue()
        ->and($module->requiresConfirmation('archive_attendance_policy'))->toBeTrue()
        ->and($module->requiresConfirmation('create_attendance_policy'))->toBeFalse()
        ->and($module->isReadOnly('get_worked_example'))->toBeTrue();
});

test('without the directory, a real name and a made-up one get the same answer', function () {
    actingAsSuperAdmin();
    Employee::factory()->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null]);

    $viewer = actingAsUserWith(['setup.attendance-policies.view']);

    expect(policiesAgent($viewer, 'get_applicable_policy', ['employee' => 'Maria Santos'])->detail)
        ->toBe(policiesAgent($viewer, 'get_applicable_policy', ['employee' => 'Nobody Atall'])->detail);
});

test('the capture controls are neither offered nor read out', function () {
    $user = actingAsSuperAdmin();
    policyNamed('Office', ['capture' => ['geofence' => 'block', 'web_ip_allowlist' => ['203.0.113.0/24'], 'selfie_required' => true]]);

    $declared = json_encode(app(AttendancePoliciesModule::class)->tools($user));
    $read = json_encode(policiesAgent($user, 'get_attendance_policy', ['policy' => 'Office'])->cards);

    expect($declared)->not->toContain('allowed_sources')->not->toContain('selfie')->not->toContain('geofence')
        ->not->toContain('ip_allowlist')->not->toContain('offline_window')->not->toContain('early_clock_in_minutes')
        ->and($read)->toContain('selfie required, on site only, web punches from 1 office network only')
        ->not->toContain('203.0.113');
});

// ── Reading ──────────────────────────────────────────────────────────────────

test('a policy is explained group by group, with where it is named and whom it judges', function () {
    $user = actingAsSuperAdmin();
    $policy = policyNamed('Head office', AttendancePolicyPresets::find('ph_labor_code')['settings'], default: true);
    $schedule = WorkSchedule::create(['name' => 'Day Shift', 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7, 'attendance_policy_id' => $policy->id]);
    Employee::factory()->count(2)->create();

    $meta = implode(' | ', policiesAgent($user, 'get_attendance_policy', ['policy' => 'head office'])->cards[0]['meta']);

    expect($meta)->toContain('Overtime: After 8h a day, needs approval')
        ->toContain('Breaks: 1h unpaid after 5h if none is punched')
        ->toContain('Night differential: 22:00–06:00')
        ->toContain('Named on schedules Day Shift')
        ->toContain('Judges 2 people today')
        ->and($schedule->exists)->toBeTrue();
});

test('a sample day is judged by the real evaluator, and nothing is recorded', function () {
    $user = actingAsSuperAdmin();

    $example = policiesAgent($user, 'get_worked_example', ['preset' => 'ph_labor_code', 'time_in' => '8:20', 'time_out' => '19:00']);
    $bad = policiesAgent($user, 'get_worked_example', ['built_in' => true, 'time_in' => 'twenty past eight']);

    $meta = implode(' | ', $example->cards[0]['meta']);

    expect($example->cards[0]['title'])->toBe('Late')
        ->and($example->cards[0]['subtitle'])->toContain('Philippines — Labor Code (preset): a 08:00–17:00 shift, in 08:20, out 19:00')
        ->and($meta)->toContain('Late 20m')->toContain('overtime 1h 40m, of it approved 0m')->toContain('unapproved overtime')
        ->and($bad->failed())->toBeTrue()
        ->and($bad->detail)->toContain('time_in')
        ->and(AttendanceRecord::query()->count())->toBe(0);
});

test('which policy judges a person is said with the reason', function () {
    $user = actingAsSuperAdmin();
    $policy = policyNamed('Shift work', AttendancePolicyPresets::find('shift_work')['settings']);
    $schedule = WorkSchedule::create(['name' => 'Night Shift', 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7, 'attendance_policy_id' => $policy->id]);
    $maria = Employee::factory()->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null]);
    app(ScheduleAssigner::class)->assign($maria, $schedule, '2026-09-01');
    Employee::factory()->create(['first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Cruz', 'suffix' => null]);

    $hers = policiesAgent($user, 'get_applicable_policy', ['employee' => 'Maria Santos'])->cards[0];
    $his = policiesAgent($user, 'get_applicable_policy', ['employee' => 'Ben Cruz', 'date' => '2026-10-01'])->cards[0];

    expect($hers['title'])->toBe('Shift work')
        ->and($hers['subtitle'])->toContain('named on their schedule (Night Shift)')
        ->and($his['title'])->toBe('Built-in rules')
        ->and($his['subtitle'])->toContain('Thu, Oct 1, 2026')->toContain('built-in rules apply');
});

test('a question about the attendance rules reads the policies before the model is called', function () {
    $user = actingAsSuperAdmin();
    policyNamed('Head office', AttendancePolicyPresets::find('ph_labor_code')['settings'], default: true);

    $brief = app(Retriever::class)->retrieve($user, 'what are our attendance rules?');

    expect($brief?->isAboutWorkspace())->toBeTrue()
        ->and($brief->toPrompt())->toContain('Head office (company default): After 8h a day, needs approval')
        ->toContain('Which applies: the assignment’s policy');
});

// ── Doing ────────────────────────────────────────────────────────────────────

test('a policy is created from a preset with its settings adjusted, by the screen’s rules', function () {
    $user = actingAsSuperAdmin();
    policyNamed('Taken');

    $created = policiesAgent($user, 'create_attendance_policy', ['name' => 'Head office', 'preset' => 'ph_labor_code', 'grace_minutes' => 10, 'late_half_day_after_minutes' => 120]);
    $contradicts = policiesAgent($user, 'create_attendance_policy', ['name' => 'Strict', 'late_half_day_after_minutes' => 120, 'late_absent_after_minutes' => 60]);
    $range = policiesAgent($user, 'create_attendance_policy', ['name' => 'Lenient', 'grace_minutes' => 500]);
    $duplicate = policiesAgent($user, 'create_attendance_policy', ['name' => 'taken']);

    $settings = AttendancePolicy::query()->where('name', 'Head office')->firstOrFail()->settings();

    expect($created->failed())->toBeFalse()
        ->and($created->detail)->toContain('judges nobody until')
        ->and($settings->graceMinutes)->toBe(10)
        ->and($settings->lateHalfDayAfterMinutes)->toBe(120)
        ->and($settings->overtimeRequiresApproval)->toBeTrue()
        ->and($settings->nightEnabled)->toBeTrue()
        ->and($contradicts->detail)->toBe('An absence has to take more lateness than a half day does.')
        ->and($range->detail)->toContain('grace_minutes')
        ->and($duplicate->detail)->toBe('There is already a policy with that name.')
        ->and(ActivityLog::query()->where('description', 'Created attendance policy "Head office" via assistant')->exists())->toBeTrue();
});

test('an edit says what changed, turns a threshold off with 0, and refuses a change that changes nothing', function () {
    $user = actingAsSuperAdmin();
    policyNamed('Office', ['lateness' => ['grace_minutes' => 5, 'half_day_after_minutes' => 120]]);

    $edited = policiesAgent($user, 'update_attendance_policy', ['policy' => 'Office', 'grace_from_schedule' => true, 'late_half_day_after_minutes' => 0, 'overtime_requires_approval' => true]);
    $same = policiesAgent($user, 'update_attendance_policy', ['policy' => 'Office', 'overtime_requires_approval' => true]);
    $both = policiesAgent($user, 'update_attendance_policy', ['policy' => 'Office', 'grace_from_schedule' => true, 'grace_minutes' => 3]);

    $settings = AttendancePolicy::query()->where('name', 'Office')->firstOrFail()->settings();

    expect($edited->failed())->toBeFalse()
        ->and($edited->cards[0]['subtitle'])->toBe('Lateness: 5m grace a day, half day past 2h → Each schedule’s grace; Overtime: After the day’s hours → After the day’s hours, needs approval')
        ->and($settings->graceMinutes)->toBeNull()
        ->and($settings->lateHalfDayAfterMinutes)->toBeNull()
        ->and($same->failed())->toBeTrue()
        ->and($both->failed())->toBeTrue()
        ->and(ActivityLog::query()->where('description', 'Updated attendance policy "Office" via assistant')->exists())->toBeTrue();
});

test('the confirmation says how many people a policy judges today', function () {
    $user = actingAsSuperAdmin();
    policyNamed('Office', default: true);
    policyNamed('Unused');
    Employee::factory()->count(3)->create();

    $module = app(AttendancePoliciesModule::class);

    expect($module->consequence($user, 'update_attendance_policy', ['policy' => 'Office']))->toContain('It judges 3 people today')
        ->and($module->consequence($user, 'archive_attendance_policy', ['policy' => 'Unused']))->toContain('It judges 0 people today')
        ->and($module->consequence($user, 'set_default_attendance_policy', ['policy' => 'Unused']))->toContain('3 people')->toContain('(now Office)');
});

test('the default moves, clears, and an archived policy stops being it', function () {
    $user = actingAsSuperAdmin();
    policyNamed('Office', default: true);
    policyNamed('Plant');

    policiesAgent($user, 'set_default_attendance_policy', ['policy' => 'Plant']);

    expect(AttendancePolicy::query()->where('is_default', true)->pluck('name')->all())->toBe(['Plant']);

    policiesAgent($user, 'archive_attendance_policy', ['policy' => 'Plant']);

    expect(AttendancePolicy::query()->where('is_default', true)->exists())->toBeFalse()
        ->and(policiesAgent($user, 'set_default_attendance_policy', ['clear' => true])->detail)->toContain('no company default to clear');
});

test('a policy whose name was reused while archived is not restored — on the screen either', function () {
    $user = actingAsSuperAdmin();
    $old = policyNamed('Office');
    $old->delete();
    policyNamed('Office');

    expect(policiesAgent($user, 'restore_attendance_policy', ['policy' => 'Office'])->detail)->toContain('Another policy is already called "Office"');

    $this->patch(route('setup.attendance-policies.restore', $old->hashid));

    assertToast('warning', 'Another policy is already called');
    expect(AttendancePolicy::query()->where('name', 'Office')->count())->toBe(1);
});

test('another workspace’s policies are never found', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => policyNamed('Their Policy'));
    app(Tenancy::class)->set($mine);

    expect(policiesAgent($user, 'find_attendance_policies')->cards)->toBe([])
        ->and(policiesAgent($user, 'get_attendance_policy', ['policy' => 'Their Policy'])->failed())->toBeTrue()
        ->and(policiesAgent($user, 'create_attendance_policy', ['name' => 'Their Policy'])->failed())->toBeFalse();
});

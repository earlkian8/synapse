<?php

use App\Http\Controllers\Attendance\AttendanceExportController;
use App\Models\ActivityLog;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\WorkSchedule;
use App\Queries\AttendanceMonthlyReport;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\PolicyResolver;
use App\Support\Attendance\ScheduleAssigner;
use App\Support\Attendance\SchedulePatternWriter;
use App\Support\Attendance\ShiftResolver;
use App\Support\Setup\CompanySetup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| ADR 0038 — attendance policies: presets and typed options, resolved by one
| precedence chain, snapshotted per day, and turned into minute buckets.
|
| 2026-09-14 is a Monday. Times are wall-clock readings in Asia/Manila.
*/

/** A Mon–Fri 08:00–17:00 schedule written through the pattern writer. */
function policySchedule(string $name = 'Day Shift', array $attributes = []): WorkSchedule
{
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    $schedule = WorkSchedule::create(['name' => $name, 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7, ...$attributes]);

    app(SchedulePatternWriter::class)->write($schedule, array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '08:00', 'end' => '17:00']],
        'required_minutes' => 480,
    ], range(0, 6)));

    return $schedule->refresh();
}

/** A policy from a preset (or the fallback), with settings laid over it. */
function makePolicy(string $name, array $settings = [], bool $default = false, ?string $preset = null): AttendancePolicy
{
    // Bind the tenant first, so the policy is stamped with it whatever the test
    // did before calling this.
    testOrganization();

    $base = $preset !== null ? AttendancePolicyPresets::find($preset)['settings'] : [];

    return AttendancePolicy::create([
        'name' => $name,
        'preset_key' => $preset,
        'settings' => AttendancePolicySettings::fromArray(array_replace_recursive($base, $settings))->toArray(),
        'settings_version' => AttendancePolicySettings::VERSION,
        'is_default' => $default,
    ]);
}

/** An employee on a schedule from September 1st. */
function policyWorker(WorkSchedule $schedule, ?int $policyId = null, array $attributes = []): Employee
{
    $employee = Employee::factory()->create(['work_schedule_id' => null, ...$attributes]);
    app(ScheduleAssigner::class)->assign($employee, $schedule, '2026-09-01', attendancePolicyId: $policyId);

    return $employee->refresh();
}

/** HR's manual entry of one day, through the real endpoint. */
function enterDay(Employee $employee, string $date, string $in, string $out, ?string $breakStart = null, ?string $breakEnd = null): AttendanceRecord
{
    test()->post(route('attendance.store'), array_filter([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'time_in' => $in,
        'break_start' => $breakStart,
        'break_end' => $breakEnd,
        'time_out' => $out,
    ]))->assertSessionHasNoErrors();

    return AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $date)->sole();
}

/** The complete settings payload a client posts. */
function settingsPayload(array $overrides = []): array
{
    return array_replace_recursive(AttendancePolicySettings::fallback()->toArray(), $overrides);
}

// ── The screen and its permissions ───────────────────────────────────────────

test('the attendance policies screen renders with the presets on offer', function () {
    actingAsSuperAdmin();
    makePolicy('Office', default: true);

    $this->get(route('setup.attendance-policies.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/attendance-policies')
            ->has('policies', 1)
            ->where('policies.0.name', 'Office')
            ->where('policies.0.is_default', true)
            ->has('presets', count(AttendancePolicyPresets::all()))
            ->has('fallback.overtime')
            ->where('can.manage', true));
});

test('viewing and managing policies are separate permissions', function () {
    actingAsUserWith(['setup.attendance-policies.view']);

    $this->get(route('setup.attendance-policies.index'))->assertOk();
    $this->post(route('setup.attendance-policies.store'), ['name' => 'X', 'settings' => settingsPayload()])->assertForbidden();

    actingAsUserWith([]);

    $this->get(route('setup.attendance-policies.index'))->assertForbidden();
});

// ── CRUD ─────────────────────────────────────────────────────────────────────

test('a policy is created from a preset, stored complete, and logged', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.attendance-policies.store'), [
        'name' => 'Head office',
        'preset_key' => 'ph_labor_code',
        'is_default' => true,
        'settings' => AttendancePolicyPresets::settings('ph_labor_code')->toArray(),
    ])->assertSessionHasNoErrors();

    $policy = AttendancePolicy::sole();

    expect($policy->is_default)->toBeTrue()
        ->and($policy->preset_key)->toBe('ph_labor_code')
        ->and($policy->settings_version)->toBe(AttendancePolicySettings::VERSION)
        ->and($policy->settings())->toEqual(AttendancePolicyPresets::settings('ph_labor_code'))
        ->and(ActivityLog::where('description', 'like', 'Created attendance policy "Head office"%')->exists())->toBeTrue();
});

test('only one policy is the company default at a time', function () {
    actingAsSuperAdmin();
    $first = makePolicy('First', default: true);
    $second = makePolicy('Second');

    $this->patch(route('setup.attendance-policies.default', $second->hashid))->assertSessionHasNoErrors();

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue();

    // Pressing it again clears it: everybody falls back to the built-in rules.
    $this->patch(route('setup.attendance-policies.default', $second->hashid));

    expect(AttendancePolicy::where('is_default', true)->exists())->toBeFalse();
});

test('settings that contradict each other are refused', function (array $overrides, string $field) {
    actingAsSuperAdmin();

    $this->post(route('setup.attendance-policies.store'), [
        'name' => 'Broken',
        'settings' => settingsPayload($overrides),
    ])->assertSessionHasErrors($field);
})->with([
    'absence needs more lateness than a half day' => [['lateness' => ['half_day_after_minutes' => 120, 'absent_after_minutes' => 60]], 'settings.lateness.absent_after_minutes'],
    'minimum below the half-day line' => [['undertime' => ['half_day_below_minutes' => 240, 'minimum_minutes_for_present' => 300]], 'settings.undertime.minimum_minutes_for_present'],
    'a monthly allowance with no minutes' => [['lateness' => ['grace_mode' => 'monthly_allowance', 'monthly_grace_minutes' => 0]], 'settings.lateness.monthly_grace_minutes'],
    'a rounding unit not on offer' => [['rounding' => ['unit' => 7]], 'settings.rounding.unit'],
    'no way to punch at all' => [['capture' => ['allowed_sources' => null]], 'settings.capture.allowed_sources'],
    'an address that is not one' => [['capture' => ['web_ip_allowlist' => ['not-an-ip']]], 'settings.capture.web_ip_allowlist.0'],
    'a night window of no length' => [['night' => ['start' => '22:00', 'end' => '22:00']], 'settings.night.end'],
]);

test('an office network may be a single address or a range', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.attendance-policies.store'), [
        'name' => 'Office network',
        'settings' => settingsPayload(['capture' => ['web_ip_allowlist' => ['203.0.113.7', '198.51.100.0/24', '2001:db8::/32']]]),
    ])->assertSessionHasNoErrors();

    expect(AttendancePolicy::sole()->settings()->webIpAllowlist)->toHaveCount(3);
});

test('an archived policy stops being the default, and one still in use cannot be deleted', function () {
    actingAsSuperAdmin();
    $policy = makePolicy('Retired', default: true);
    policySchedule('Uses it', ['attendance_policy_id' => $policy->id]);

    $this->delete(route('setup.attendance-policies.destroy', $policy->hashid))->assertSessionHasNoErrors();

    expect(AttendancePolicy::withTrashed()->find($policy->id)->is_default)->toBeFalse();

    $this->delete(route('setup.attendance-policies.force-delete', $policy->hashid));

    expect(AttendancePolicy::withTrashed()->find($policy->id))->not->toBeNull();

    WorkSchedule::query()->update(['attendance_policy_id' => null]);
    $this->delete(route('setup.attendance-policies.force-delete', $policy->hashid));

    expect(AttendancePolicy::withTrashed()->find($policy->id))->toBeNull();
});

// ── Precedence ───────────────────────────────────────────────────────────────

test('the policy resolves assignment, then schedule, then department, then company, then fallback', function () {
    $company = makePolicy('Company', default: true);
    $departmentPolicy = makePolicy('Department');
    $schedulePolicy = makePolicy('Schedule');
    $personal = makePolicy('Personal');

    $department = Department::factory()->create(['attendance_policy_id' => $departmentPolicy->id]);
    $plain = policySchedule('Plain');
    $judged = policySchedule('Judged', ['attendance_policy_id' => $schedulePolicy->id]);

    $resolve = fn (Employee $employee) => app(PolicyResolver::class)->for($employee, (new ShiftResolver)->for($employee, '2026-09-14'));

    $nobody = policyWorker($plain);
    $inDepartment = policyWorker($plain, attributes: ['department_id' => $department->id]);
    $onJudged = policyWorker($judged, attributes: ['department_id' => $department->id]);
    $singledOut = policyWorker($judged, $personal->id, ['department_id' => $department->id]);

    expect($resolve($nobody))->source->toBe('organization')->name->toBe('Company')
        ->and($resolve($inDepartment))->source->toBe('department')->name->toBe('Department')
        ->and($resolve($onJudged))->source->toBe('schedule')->name->toBe('Schedule')
        ->and($resolve($singledOut))->source->toBe('assignment')->name->toBe('Personal');

    $company->forceFill(['is_default' => false])->save();

    expect(app(PolicyResolver::class)->for($nobody, (new ShiftResolver)->for($nobody, '2026-09-14')))
        ->source->toBe('fallback')
        ->id->toBeNull();
});

test('a company with no policies resolves a month for everybody in one query', function () {
    $schedule = policySchedule();
    $employees = collect(range(1, 10))->map(fn () => policyWorker($schedule));
    $shifts = (new ShiftResolver)->forMany($employees, '2026-09-01', '2026-09-30');

    DB::enableQueryLog();
    $policies = (new PolicyResolver)->forMany($employees, $shifts);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1)
        ->and($policies[$employees->first()->id]['2026-09-30']->source)->toBe('fallback');
});

test('resolving policies for a month costs a fixed number of queries', function () {
    makePolicy('Company', default: true);
    $schedule = policySchedule('Judged', ['attendance_policy_id' => makePolicy('Schedule')->id]);
    $employees = collect(range(1, 20))->map(fn () => policyWorker($schedule));
    $shifts = (new ShiftResolver)->forMany($employees, '2026-09-01', '2026-09-30');

    DB::enableQueryLog();
    (new PolicyResolver)->forMany($employees, $shifts);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(6);
});

test('splitting an assignment keeps the policy on both halves', function () {
    $personal = makePolicy('Personal');
    $day = policySchedule('Day');
    $night = policySchedule('Night');
    $employee = policyWorker($day, $personal->id);

    app(ScheduleAssigner::class)->assign($employee, $night, '2026-09-10', '2026-09-12');

    expect($employee->scheduleAssignments()->orderBy('effective_from')->pluck('attendance_policy_id')->all())
        ->toBe([$personal->id, null, $personal->id]);
});

// ── A day is judged by the policy it opened with ─────────────────────────────

test('a recorded day carries its policy, buckets and flags', function () {
    actingAsSuperAdmin();
    makePolicy('PH', default: true, preset: 'ph_labor_code');
    $employee = policyWorker(policySchedule());

    $record = enterDay($employee, '2026-09-14', '08:00', '19:00');

    expect($record->rules['version'])->toBe(3)
        ->and($record->rules['policy']['name'])->toBe('PH')
        ->and($record->rules['policy']['source'])->toBe('organization')
        ->and($record->worked_minutes)->toBe(600)
        ->and($record->regular_minutes)->toBe(480)
        ->and($record->overtime_minutes)->toBe(120)
        ->and($record->approved_overtime_minutes)->toBe(0)
        ->and($record->flags)->toContain('break_deducted', 'unapproved_overtime');

    $this->get(route('attendance.show', $record->hashid))
        ->assertOk()
        ->assertJsonPath('data.policy.name', 'PH')
        ->assertJsonPath('data.regular_minutes', 480)
        ->assertJsonPath('data.flags', ['break_deducted', 'unapproved_overtime']);
});

test('editing a policy leaves recorded days alone until it is re-applied', function () {
    actingAsSuperAdmin();
    $policy = makePolicy('Office', ['lateness' => ['grace_minutes' => 0]], default: true);
    $employee = policyWorker(policySchedule());
    $record = enterDay($employee, '2026-09-14', '08:10', '17:00');

    expect($record->late_minutes)->toBe(10);

    $this->post(route('setup.attendance-policies.update', $policy->hashid), [
        'name' => 'Office',
        'settings' => settingsPayload(['lateness' => ['grace_minutes' => 15]]),
    ])->assertSessionHasNoErrors();

    // A correction recomputes from the snapshot, which still says no grace.
    app(AttendanceClock::class)->refresh($record->refresh());

    expect($record->refresh()->late_minutes)->toBe(10);

    $this->patch(route('attendance.reapply', $record->hashid))->assertSessionHasNoErrors();

    expect($record->refresh()->late_minutes)->toBe(0)
        ->and($record->status)->toBe('present')
        ->and($record->rules['policy']['settings']['lateness']['grace_minutes'])->toBe(15);
});

test('weekly overtime reads the week as it was recorded', function () {
    actingAsSuperAdmin();
    makePolicy('Weekly', ['overtime' => ['basis' => 'weekly', 'weekly_after_minutes' => 2400]], default: true);
    $employee = policyWorker(policySchedule());

    // Mon–Thu: ten hours each is 2,400 minutes — the whole threshold.
    foreach (['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'] as $date) {
        expect(enterDay($employee, $date, '08:00', '18:00')->overtime_minutes)->toBe(0);
    }

    $friday = enterDay($employee, '2026-09-18', '08:00', '17:00');

    expect($friday->overtime_minutes)->toBe(540)
        ->and($friday->regular_minutes)->toBe(0);

    // The next week starts from nothing.
    expect(enterDay($employee, '2026-09-21', '08:00', '18:00')->overtime_minutes)->toBe(0);
});

test('the fourth late day of the month is the one past the allowance', function () {
    actingAsSuperAdmin();
    makePolicy('Allowance', ['lateness' => ['grace_mode' => 'monthly_allowance', 'monthly_grace_minutes' => 30]], default: true);
    $employee = policyWorker(policySchedule());

    $days = collect(['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'])
        ->map(fn (string $date) => enterDay($employee, $date, '08:10', '17:00'));

    expect($days->pluck('late_minutes')->all())->toBe([0, 0, 0, 10])
        ->and($days->pluck('status')->all())->toBe(['present', 'present', 'present', 'late']);
});

test('re-applying a period judges its days in date order', function () {
    actingAsSuperAdmin();
    $employee = policyWorker(policySchedule());

    // Recorded under the fallback, so every late day is late.
    foreach (['2026-09-17', '2026-09-16', '2026-09-15', '2026-09-14'] as $date) {
        enterDay($employee, $date, '08:10', '17:00');
    }

    makePolicy('Allowance', ['lateness' => ['grace_mode' => 'monthly_allowance', 'monthly_grace_minutes' => 30]], default: true);

    $this->patch(route('attendance.reapply-range'), ['from' => '2026-09-14', 'to' => '2026-09-17'])->assertSessionHasNoErrors();

    expect(AttendanceRecord::orderBy('work_date')->pluck('late_minutes')->all())->toBe([0, 0, 0, 10]);
});

test('an open shift claims punches for as long as its own policy allows', function (int $span, string $at, string $expected) {
    makePolicy('Span', ['punch_windows' => ['max_shift_span_minutes' => $span]], default: true);
    $employee = policyWorker(policySchedule());
    $clock = app(AttendanceClock::class);

    // An evening clock-in, outside the day shift's window, opens Monday.
    $this->travelTo(CarbonImmutable::parse('2026-09-14 20:00', 'Asia/Manila'));
    $clock->punch($employee, 'clock_in');

    expect($clock->workDateFor($employee, CarbonImmutable::parse($at, 'Asia/Manila'), 'clock_out'))->toBe($expected);
})->with([
    'sixteen hours, seven hours on' => [960, '2026-09-15 03:00', '2026-09-14'],
    'sixteen hours, nine hours on' => [960, '2026-09-15 05:00', '2026-09-14'],
    'eight hours, seven hours on' => [480, '2026-09-15 03:00', '2026-09-14'],
    'eight hours, nine hours on' => [480, '2026-09-15 05:00', '2026-09-15'],
]);

// ── The worked example ───────────────────────────────────────────────────────

test('the worked example judges a sample day without writing anything', function () {
    actingAsUserWith(['setup.attendance-policies.view']);
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    $this->postJson(route('setup.attendance-policies.preview'), [
        'settings' => settingsPayload([
            'lateness' => ['grace_minutes' => 10],
            'breaks' => ['auto_deduct_minutes' => 60, 'auto_deduct_after_worked_minutes' => 300],
            'overtime' => ['daily_after_minutes' => 480, 'min_block_minutes' => 60],
        ]),
        'sample' => [
            'day' => 'working', 'shift_start' => '08:00', 'shift_end' => '17:00',
            'required_minutes' => 480, 'grace_minutes' => 0,
            'time_in' => '08:07', 'time_out' => '17:42', 'break_start' => null, 'break_end' => null,
        ],
    ])
        ->assertOk()
        // In 08:07, out 17:42, no break punched → 8h 35m regular, 0 overtime
        // (35 minutes is under the hour's block), late 0 inside grace, 1h lunch.
        ->assertJsonPath('result.regular_minutes', 515)
        ->assertJsonPath('result.overtime_minutes', 0)
        ->assertJsonPath('result.late_minutes', 0)
        ->assertJsonPath('result.excused_late_minutes', 7)
        ->assertJsonPath('result.break_minutes', 60)
        ->assertJsonPath('result.status', 'present');

    expect(AttendanceRecord::count())->toBe(0);
});

test('the worked example refuses settings the policy could not be saved with', function () {
    actingAsSuperAdmin();

    $this->postJson(route('setup.attendance-policies.preview'), [
        'settings' => settingsPayload(['lateness' => ['half_day_after_minutes' => 120, 'absent_after_minutes' => 60]]),
        'sample' => ['day' => 'working', 'shift_start' => '08:00', 'shift_end' => '17:00', 'required_minutes' => 480, 'grace_minutes' => 0, 'time_in' => '08:00'],
    ])->assertUnprocessable()->assertJsonValidationErrors('settings.lateness.absent_after_minutes');
});

// ── Attaching a policy ───────────────────────────────────────────────────────

test('a schedule, a department and an assignment can each name a policy', function () {
    actingAsSuperAdmin();
    $policy = makePolicy('Night rules');
    $schedule = policySchedule();
    $employee = Employee::factory()->create();

    $this->post(route('setup.schedule.work-schedules.update', $schedule->hashid), [
        'name' => $schedule->name,
        'type' => 'fixed',
        'cycle_length_days' => 7,
        'grace_minutes' => 0,
        'attendance_policy_id' => $policy->id,
        'days' => array_map(fn (int $i): array => ['is_rest_day' => $i >= 5, 'segments' => [['start' => '08:00', 'end' => '17:00']], 'required_minutes' => 480], range(0, 6)),
    ])->assertSessionHasNoErrors();

    $this->post(route('attendance.roster.assign'), [
        'employee_ids' => [$employee->id],
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-09-01',
        'attendance_policy_id' => $policy->id,
    ])->assertSessionHasNoErrors();

    expect($schedule->refresh()->attendance_policy_id)->toBe($policy->id)
        ->and($employee->scheduleAssignments()->sole()->attendance_policy_id)->toBe($policy->id);
});

test('a policy from another company cannot be attached', function () {
    actingAsSuperAdmin();
    $schedule = policySchedule();
    $foreign = DB::table('attendance_policies')->insertGetId([
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'Theirs', 'settings' => '{}', 'settings_version' => 1, 'is_default' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->post(route('attendance.roster.assign'), [
        'employee_ids' => [Employee::factory()->create()->id],
        'work_schedule_id' => $schedule->id,
        'effective_from' => '2026-09-01',
        'attendance_policy_id' => $foreign,
    ])->assertSessionHasErrors('attendance_policy_id');
});

// ── The wizard ───────────────────────────────────────────────────────────────

test('the wizard offers the presets and walks six steps', function () {
    actingAsSuperAdmin();
    testOrganization()->forceFill(['setup_completed_at' => null, 'setup_steps' => null])->save();

    expect(CompanySetup::STEPS)->toBe(['company', 'departments', 'leave-types', 'attendance', 'recruitment', 'performance']);

    $this->get(route('setup.wizard.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('blueprints.attendancePolicies', count(AttendancePolicyPresets::all()))
            ->where('progress.steps.attendance', CompanySetup::PENDING)
            ->where('can.attendance', true)
            ->has('existing.attendancePolicies')
            ->has('existing.schedules'));
});

test('the attendance step adopts a preset as the default, with a default schedule', function () {
    actingAsSuperAdmin();
    $organization = testOrganization();
    $organization->forceFill(['timezone' => 'Asia/Manila', 'setup_steps' => null])->save();

    $this->post(route('setup.wizard.attendance'), [
        'preset' => 'ph_labor_code',
        // Settings posted alongside an adopted preset are not what is stored.
        'settings' => settingsPayload(['night' => ['enabled' => false]]),
        'schedule' => ['create' => true, 'name' => 'Office Hours', 'start' => '08:00', 'end' => '17:00', 'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']],
    ])->assertSessionHasNoErrors();

    $policy = AttendancePolicy::sole();
    $schedule = WorkSchedule::sole();

    expect($policy->name)->toBe('Philippines — Labor Code')
        ->and($policy->is_default)->toBeTrue()
        ->and($policy->settings())->toEqual(AttendancePolicyPresets::settings('ph_labor_code'))
        ->and($schedule->name)->toBe('Office Hours')
        ->and($schedule->work_days)->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
        ->and($schedule->patternDays()->get(1)->required_minutes)->toBe(480)
        ->and($organization->refresh()->default_work_schedule_id)->toBe($schedule->id)
        ->and(CompanySetup::statuses($organization)['attendance'])->toBe(CompanySetup::DONE);

    // …and it applies from the first punch.
    $employee = Employee::factory()->create(['work_schedule_id' => null]);
    $record = enterDay($employee, '2026-09-14', '08:00', '19:00');

    expect($record->rules['policy']['name'])->toBe('Philippines — Labor Code')
        ->and($record->overtime_minutes)->toBe(120);
});

test('the attendance step keeps a customised preset as the company wrote it', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.wizard.attendance'), [
        'preset' => 'shift_work',
        'name' => 'Warehouse rules',
        'customised' => true,
        'settings' => array_replace_recursive(
            AttendancePolicyPresets::settings('shift_work')->toArray(),
            ['rounding' => ['unit' => 5]],
        ),
    ])->assertSessionHasNoErrors();

    $policy = AttendancePolicy::sole();

    expect($policy->name)->toBe('Warehouse rules')
        ->and($policy->preset_key)->toBe('shift_work')
        ->and($policy->settings()->roundingUnit)->toBe(5)
        ->and(WorkSchedule::count())->toBe(0);
});

test('the attendance step refuses a preset that is not on offer, and a broken customisation', function () {
    actingAsSuperAdmin();

    $this->post(route('setup.wizard.attendance'), ['preset' => 'four_day_week'])->assertSessionHasErrors('preset');

    $this->post(route('setup.wizard.attendance'), [
        'preset' => 'shift_work',
        'customised' => true,
        'settings' => settingsPayload(['undertime' => ['half_day_below_minutes' => 60, 'minimum_minutes_for_present' => 120]]),
    ])->assertSessionHasErrors('settings.undertime.minimum_minutes_for_present');

    expect(AttendancePolicy::count())->toBe(0);
});

test('writing the default schedule in the wizard needs the schedule permission too', function () {
    actingAsUserWith(['setup.attendance-policies.manage']);

    $this->post(route('setup.wizard.attendance'), [
        'preset' => 'standard_40h_week',
        'schedule' => ['create' => true, 'name' => 'Office', 'start' => '09:00', 'end' => '18:00', 'days' => ['Mon']],
    ])->assertForbidden();

    $this->post(route('setup.wizard.attendance'), ['preset' => 'standard_40h_week'])->assertSessionHasNoErrors();

    expect(AttendancePolicy::sole()->name)->toBe('Standard 40-hour week');
});

// ── Reports and export ───────────────────────────────────────────────────────

test('the period summary exports every bucket, one row per employee', function () {
    actingAsSuperAdmin();
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00', 'Asia/Manila'));
    makePolicy('PH', default: true, preset: 'ph_labor_code');
    $employee = policyWorker(policySchedule());

    enterDay($employee, '2026-09-14', '08:00', '19:00');
    enterDay($employee, '2026-09-15', '08:00', '17:00');

    $response = $this->get(route('attendance.export', ['tab' => 'period', 'from' => '2026-09-14', 'to' => '2026-09-15']))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="attendance-period-2026-09-14-to-2026-09-15.csv"');

    $rows = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));
    $header = $rows[0];
    $row = array_combine($header, collect($rows)->first(fn (array $line): bool => $line[1] === $employee->employee_no));

    expect($header)->toBe(AttendanceExportController::PERIOD_COLUMNS)
        ->and($row['Days Worked'])->toBe('2')
        ->and($row['Worked (min)'])->toBe('1080')
        ->and($row['Regular (min)'])->toBe('960')
        ->and($row['Overtime (min)'])->toBe('120')
        ->and($row['Approved Overtime (min)'])->toBe('0');
});

test('a period summary is capped at 62 days', function () {
    actingAsSuperAdmin();

    $this->get(route('attendance.export', ['tab' => 'period', 'from' => '2026-01-01', 'to' => '2026-04-01']))
        ->assertStatus(422);
});

test('the monthly report counts half days and carries the buckets', function () {
    actingAsSuperAdmin();
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00', 'Asia/Manila'));
    makePolicy('Strict', ['lateness' => ['half_day_after_minutes' => 120]], default: true);
    $employee = policyWorker(policySchedule());

    enterDay($employee, '2026-09-14', '10:30', '17:00');

    $row = collect(app(AttendanceMonthlyReport::class)->toArray('2026-09-14')['rows'])
        ->firstWhere('employee.id', $employee->id);

    expect($row['half_day_count'])->toBe(1)
        ->and($row['present_days'])->toBe(1)
        ->and($row['minutes']['worked'])->toBe(390);
});

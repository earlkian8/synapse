<?php

use App\Models\ActivityLog;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Assistant\Modules\SchedulesModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\ScheduleAssigner;
use App\Support\Attendance\SchedulePatternWriter;
use App\Support\Tenancy;

/*
| The work-schedule and holiday capability of the assistant: the holiday
| calendar and the shift templates, changed by the Work Schedule & Holidays
| screen's own rules — and a schedule chat cannot describe is never flattened.
| Gemini is never called.
*/

beforeEach(function () {
    $this->travelTo('2026-09-28 10:00:00');
});

function schedulesAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(SchedulesModule::class)->run($user, $tool, $args);
}

function schedulesAgentTools(User $user): array
{
    return array_column(app(SchedulesModule::class)->tools($user), 'name');
}

/** A holiday in the current test tenant. */
function holidayOn(string $name, string $date, string $type = 'regular', bool $recurring = false): Holiday
{
    return Holiday::create(['name' => $name, 'date' => $date, 'type' => $type, 'is_recurring' => $recurring]);
}

/**
 * A weekly schedule from one set of hours, or from explicit day rows.
 *
 * @param  list<array<string, mixed>>|null  $days
 */
function weeklySchedule(string $name, ?array $days = null, int $cycle = 7): WorkSchedule
{
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    $schedule = WorkSchedule::create([
        'name' => $name,
        'type' => 'fixed',
        'grace_minutes' => 0,
        'cycle_length_days' => $cycle,
        'cycle_anchor_date' => $cycle === 7 ? null : '2026-09-01',
    ]);

    app(SchedulePatternWriter::class)->write($schedule, $days ?? array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '08:00', 'end' => '17:00']],
        'required_minutes' => 480,
    ], range(0, 6)));

    return $schedule->refresh();
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('a viewer reads; a manager changes; permanent deletion is never offered', function () {
    expect(schedulesAgentTools(actingAsUserWith(['setup.schedule.view'])))
        ->toEqualCanonicalizing(['find_holidays', 'find_work_schedules', 'get_work_schedule']);

    $tools = schedulesAgentTools(actingAsUserWith(['setup.schedule.view', 'setup.schedule.manage']));

    expect($tools)->toContain('add_holiday', 'archive_holiday', 'create_work_schedule', 'update_work_schedule', 'set_default_schedule')
        ->not->toContain('delete_holiday', 'force_delete_work_schedule');
});

test('changes that reach other people’s days wait for a confirm', function () {
    $module = app(SchedulesModule::class);

    expect($module->requiresConfirmation('update_work_schedule'))->toBeTrue()
        ->and($module->requiresConfirmation('set_default_schedule'))->toBeTrue()
        ->and($module->requiresConfirmation('archive_work_schedule'))->toBeTrue()
        ->and($module->requiresConfirmation('archive_holiday'))->toBeTrue()
        ->and($module->requiresConfirmation('add_holiday'))->toBeFalse()
        ->and($module->requiresConfirmation('create_work_schedule'))->toBeFalse();
});

test('a write is refused at run time without setup.schedule.manage', function () {
    $viewer = actingAsUserWith(['setup.schedule.view']);

    expect(schedulesAgent($viewer, 'add_holiday', ['name' => 'Founders Day', 'date' => '2026-11-03', 'type' => 'regular'])->detail)->toContain('permission')
        ->and(Holiday::query()->count())->toBe(0);
});

// ── Holidays ─────────────────────────────────────────────────────────────────

test('holidays are listed in date order, a yearly one on each year the range spans', function () {
    $user = actingAsSuperAdmin();
    holidayOn('New Year’s Day', '2020-01-01', recurring: true);
    holidayOn('Company Day', '2026-11-15', 'special_non_working');
    holidayOn('Old Holiday', '2025-05-01');

    $upcoming = schedulesAgent($user, 'find_holidays');
    $year = schedulesAgent($user, 'find_holidays', ['year' => 2027]);

    expect(array_column($upcoming->cards, 'title'))->toBe(['Company Day', 'New Year’s Day'])
        ->and($upcoming->cards[1]['subtitle'])->toBe('Fri, Jan 1, 2027 · every year')
        ->and($upcoming->cards[0]['badge'])->toBe('Special (non-working)')
        ->and(array_column($year->cards, 'title'))->toBe(['New Year’s Day'])
        ->and(schedulesAgent($user, 'find_holidays', ['from' => '2026-01-01', 'to' => '2029-01-01'])->failed())->toBeTrue();
});

test('a question about holidays reads the next ones before the model is called', function () {
    $user = actingAsSuperAdmin();
    holidayOn('Bonifacio Day', '2020-11-30', recurring: true);

    $brief = app(Retriever::class)->retrieve($user, 'when is the next holiday?');

    expect($brief?->isAboutWorkspace())->toBeTrue()
        ->and($brief->toPrompt())->toContain('Next holidays: Bonifacio Day (Mon, Nov 30, 2026, Regular holiday)');
});

test('a holiday is added once, by the screen’s rules, with a note when its date has passed', function () {
    $user = actingAsSuperAdmin();

    $added = schedulesAgent($user, 'add_holiday', ['name' => 'Founders Day', 'date' => '2026-11-03', 'type' => 'special_non_working']);
    $again = schedulesAgent($user, 'add_holiday', ['name' => 'founders day', 'date' => '2026-11-03', 'type' => 'regular']);
    $vague = schedulesAgent($user, 'add_holiday', ['name' => 'Someday', 'date' => 'next friday', 'type' => 'regular']);
    $past = schedulesAgent($user, 'add_holiday', ['name' => 'Snap Holiday', 'date' => '2026-09-01', 'type' => 'regular']);

    expect($added->failed())->toBeFalse()
        ->and(Holiday::query()->where('name', 'Founders Day')->count())->toBe(1)
        ->and($again->failed())->toBeTrue()
        ->and($again->detail)->toContain('already on the calendar')
        ->and($vague->detail)->toContain('YYYY-MM-DD')
        ->and($past->detail)->toContain('days already recorded keep how they were judged')
        ->and(ActivityLog::query()->where('description', 'Added holiday "Founders Day" via assistant')->exists())->toBeTrue();
});

test('two holidays of one name are told apart by date; archiving and restoring go through the workflow', function () {
    $user = actingAsSuperAdmin();
    holidayOn('Eid', '2026-03-20');
    holidayOn('Eid', '2026-05-27');

    $ambiguous = schedulesAgent($user, 'update_holiday', ['holiday' => 'Eid', 'type' => 'special_non_working']);
    $picked = schedulesAgent($user, 'update_holiday', ['holiday' => 'Eid', 'on' => '2026-05-27', 'type' => 'special_non_working']);

    expect($ambiguous->failed())->toBeTrue()
        ->and($ambiguous->detail)->toContain('Say which date')
        ->and($picked->failed())->toBeFalse()
        ->and(Holiday::query()->whereDate('date', '2026-05-27')->value('type'))->toBe('special_non_working')
        ->and(Holiday::query()->whereDate('date', '2026-03-20')->value('type'))->toBe('regular');

    schedulesAgent($user, 'archive_holiday', ['holiday' => 'Eid', 'on' => '2026-03-20']);
    expect(Holiday::query()->count())->toBe(1);

    schedulesAgent($user, 'restore_holiday', ['holiday' => 'Eid']);
    expect(Holiday::query()->count())->toBe(2)
        ->and(ActivityLog::query()->where('description', 'Restored holiday "Eid" via assistant')->exists())->toBeTrue();
});

// ── Schedules ────────────────────────────────────────────────────────────────

test('a schedule read-out has its days, grace, policy and who works it', function () {
    $user = actingAsSuperAdmin();
    $schedule = weeklySchedule('Day Shift');
    app(ScheduleAssigner::class)->assign(Employee::factory()->create(), $schedule, '2026-09-01');
    testOrganization()->forceFill(['default_work_schedule_id' => $schedule->id])->save();

    $card = schedulesAgent($user, 'get_work_schedule', ['schedule' => 'day shift'])->cards[0];
    $meta = implode(' | ', $card['meta']);

    expect($card['badge'])->toBe('Company default')
        ->and($meta)->toContain('Mon–Fri: 08:00–17:00 · 8h required')
        ->toContain('Sat–Sun: rest day')
        ->toContain('1 assigned; 1 person working it today')
        ->toContain('Names no attendance policy');
});

test('a weekly schedule is created from one set of hours, by the screen’s rules', function () {
    $user = actingAsSuperAdmin();
    $policy = AttendancePolicy::create(['name' => 'Shift work', 'settings' => AttendancePolicySettings::fallback()->toArray(), 'settings_version' => 1]);

    $created = schedulesAgent($user, 'create_work_schedule', [
        'name' => 'Early Shift', 'work_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], 'start' => '6:00', 'end' => '15:00',
        'unpaid_break_minutes' => 60, 'grace_minutes' => 10, 'attendance_policy' => 'shift work',
    ]);
    $again = schedulesAgent($user, 'create_work_schedule', ['name' => 'early shift', 'work_days' => ['Mon'], 'start' => '06:00', 'end' => '15:00']);
    $flexible = schedulesAgent($user, 'create_work_schedule', ['name' => 'Flexi', 'type' => 'flexible', 'work_days' => ['Mon'], 'start' => '07:00', 'end' => '19:00']);
    $noHours = schedulesAgent($user, 'create_work_schedule', ['name' => 'Vague', 'work_days' => ['Mon']]);
    $noPolicy = schedulesAgent($user, 'create_work_schedule', ['name' => 'Odd', 'work_days' => ['Mon'], 'start' => '08:00', 'end' => '17:00', 'attendance_policy' => 'Nope']);

    $schedule = WorkSchedule::query()->where('name', 'Early Shift')->firstOrFail();
    $days = $schedule->patternDays();

    expect($created->failed())->toBeFalse()
        ->and($days->filter(fn ($d) => ! $d->is_rest_day)->count())->toBe(6)
        ->and($days[1]->segments)->toBe([['start' => '06:00', 'end' => '15:00']])
        ->and($days[1]->required_minutes)->toBe(480)
        ->and($days[1]->unpaid_break_minutes)->toBe(60)
        ->and($days[7]->is_rest_day)->toBeTrue()
        ->and($schedule->grace_minutes)->toBe(10)
        ->and($schedule->attendance_policy_id)->toBe($policy->id)
        ->and($again->detail)->toContain('already a schedule called')
        ->and($flexible->detail)->toContain('core hours')
        ->and($noHours->detail)->toContain('start and an end')
        ->and($noPolicy->detail)->toContain('The policies are: Shift work')
        ->and(ActivityLog::query()->where('description', 'Created work schedule "Early Shift" via assistant')->exists())->toBeTrue();
});

test('a schedule edit keeps the pattern unless the hours change, and never flattens one chat cannot describe', function () {
    $user = actingAsSuperAdmin();
    weeklySchedule('Day Shift');
    weeklySchedule('Split Shift', array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
        'required_minutes' => 480,
    ], range(0, 6)));
    weeklySchedule('Rotation', array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 4,
        'segments' => [['start' => '07:00', 'end' => '19:00']],
        'required_minutes' => 720,
    ], range(0, 7)), cycle: 8);

    $grace = schedulesAgent($user, 'update_work_schedule', ['schedule' => 'Day Shift', 'grace_minutes' => 15]);
    $hours = schedulesAgent($user, 'update_work_schedule', ['schedule' => 'Day Shift', 'start' => '09:00', 'end' => '18:00']);
    $split = schedulesAgent($user, 'update_work_schedule', ['schedule' => 'Split Shift', 'end' => '18:00']);
    $rotation = schedulesAgent($user, 'update_work_schedule', ['schedule' => 'Rotation', 'work_days' => ['Mon']]);
    $renamedSplit = schedulesAgent($user, 'update_work_schedule', ['schedule' => 'Split Shift', 'new_name' => 'Split Day']);

    $day = WorkSchedule::query()->where('name', 'Day Shift')->firstOrFail();

    expect($grace->failed())->toBeFalse()
        ->and($hours->failed())->toBeFalse()
        ->and($day->grace_minutes)->toBe(15)
        ->and($day->patternDays()[1]->segments)->toBe([['start' => '09:00', 'end' => '18:00']])
        ->and($day->patternDays()->filter(fn ($d) => ! $d->is_rest_day)->count())->toBe(5)
        ->and($split->detail)->toContain('/setup/schedule')
        ->and($rotation->detail)->toContain('a rotation')
        ->and($renamedSplit->failed())->toBeFalse()
        ->and(WorkSchedule::query()->where('name', 'Split Day')->firstOrFail()->patternDays()[1]->segments)->toHaveCount(2);
});

test('the company default is set and cleared, and the confirmation says whom it reaches', function () {
    $user = actingAsSuperAdmin();
    $schedule = weeklySchedule('Day Shift');
    Employee::factory()->count(2)->create();

    $line = app(SchedulesModule::class)->consequence($user, 'set_default_schedule', ['schedule' => 'Day Shift']);

    schedulesAgent($user, 'set_default_schedule', ['schedule' => 'Day Shift']);
    $again = schedulesAgent($user, 'set_default_schedule', ['schedule' => 'Day Shift']);

    expect($line)->toContain('2 people with no schedule of their own')
        ->and(testOrganization()->fresh()->default_work_schedule_id)->toBe($schedule->id)
        ->and($again->detail)->toContain('already the company default')
        ->and(app(SchedulesModule::class)->consequence($user, 'update_work_schedule', ['schedule' => 'Day Shift']))->toContain('2 people work it today');

    schedulesAgent($user, 'set_default_schedule', ['clear' => true]);

    expect(testOrganization()->fresh()->default_work_schedule_id)->toBeNull()
        ->and(ActivityLog::query()->where('description', "Cleared the company's default schedule via assistant")->exists())->toBeTrue();
});

test('archiving a schedule keeps it for the people on it, and restoring brings it back', function () {
    $user = actingAsSuperAdmin();
    $schedule = weeklySchedule('Night Shift');
    $employee = Employee::factory()->create();
    app(ScheduleAssigner::class)->assign($employee, $schedule, '2026-09-01');

    schedulesAgent($user, 'archive_work_schedule', ['schedule' => 'Night Shift']);

    expect(WorkSchedule::query()->where('name', 'Night Shift')->exists())->toBeFalse()
        ->and($employee->fresh()->work_schedule_id)->toBe($schedule->id);

    schedulesAgent($user, 'restore_work_schedule', ['schedule' => 'night']);

    expect(WorkSchedule::query()->where('name', 'Night Shift')->exists())->toBeTrue();
});

test('another workspace’s holidays and schedules are never found', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();
    $other = Organization::factory()->create();

    app(Tenancy::class)->runFor($other, function (): void {
        holidayOn('Their Holiday', '2026-12-01');
        WorkSchedule::create(['name' => 'Their Shift', 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7]);
    });

    app(Tenancy::class)->set($mine);

    expect(schedulesAgent($user, 'find_holidays')->cards)->toBe([])
        ->and(schedulesAgent($user, 'get_work_schedule', ['schedule' => 'Their Shift'])->failed())->toBeTrue()
        ->and(schedulesAgent($user, 'archive_holiday', ['holiday' => 'Their Holiday'])->failed())->toBeTrue();
});

<?php

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Organization;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\DayContext;
use App\Support\Attendance\DayResult;
use App\Support\Attendance\DayRules;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Collection;

/*
| ADR 0038 — the calculator becomes a day evaluator: punches + the rules frozen on
| the day (shift, holiday, attendance policy) + a context → a DayResult. It is
| pure, so every rule below is a table of sample days with no database behind it.
|
| The sample shift is Monday 2026-09-14, 08:00–17:00, eight hours required, no
| grace, in Asia/Manila. Times are wall-clock readings there; a reading earlier
| than the one before it is the next morning.
*/

const SAMPLE_DATE = '2026-09-14';
const SAMPLE_ZONE = 'Asia/Manila';

/**
 * Judge a sample day.
 *
 * @param  array<string, mixed>  $settings  Partial policy settings, laid over the fallback.
 * @param  list<array{0: string, 1: string}>  $punches  [type, "HH:MM"] in order.
 * @param  array<string, mixed>  $rules  DayRules overrides, plus `shift` => [start, end] or null.
 * @param  array<string, mixed>  $context  DayContext overrides.
 */
function judge(array $settings, array $punches, array $rules = [], array $context = []): DayResult
{
    [$start, $end] = array_key_exists('shift', $rules) ? ($rules['shift'] ?? [null, null]) : ['08:00', '17:00'];
    unset($rules['shift']);

    $at = fn (string $date, string $time): CarbonImmutable => CarbonImmutable::parse("{$date} {$time}", SAMPLE_ZONE)->utc();
    $next = CarbonImmutable::parse(SAMPLE_DATE)->addDay()->toDateString();

    $scheduledStart = $start !== null ? $at(SAMPLE_DATE, $start) : null;
    $scheduledEnd = $end !== null ? $at(SAMPLE_DATE, $end) : null;

    if ($scheduledStart !== null && $scheduledEnd !== null && $scheduledEnd->lte($scheduledStart)) {
        $scheduledEnd = $at($next, $end);
    }

    $previous = null;
    $models = collect();

    foreach ($punches as $index => [$type, $time]) {
        $instant = $at(SAMPLE_DATE, $time);

        if ($previous !== null && $instant->lt($previous)) {
            $instant = $at($next, $time);
        }

        $punch = new AttendancePunch(['type' => $type, 'punched_at' => $instant]);
        $punch->id = $index + 1;
        $models->push($punch);
        $previous = $instant;
    }

    $dayRules = new DayRules(...[
        'graceMinutes' => 0,
        'requiredMinutes' => 480,
        'isWorkingDay' => true,
        'policy' => AttendancePolicySettings::fromArray($settings),
        ...$rules,
    ]);

    return AttendanceCalculator::evaluate($models, $dayRules, new DayContext(...[
        'scheduledStart' => $scheduledStart,
        'scheduledEnd' => $scheduledEnd,
        'timezone' => SAMPLE_ZONE,
        ...$context,
    ]));
}

/** A plain 08:00–17:00 day's punches, as [type, time] pairs. */
function sampleDay(string $in = '08:00', string $out = '17:00', ?string $breakStart = null, ?string $breakEnd = null): array
{
    return array_values(array_filter([
        ['clock_in', $in],
        $breakStart !== null ? ['break_start', $breakStart] : null,
        $breakEnd !== null ? ['break_end', $breakEnd] : null,
        ['clock_out', $out],
    ]));
}

// ── The fallback is the old behaviour ────────────────────────────────────────

test('the fallback policy judges a day exactly as before policies existed', function (array $punches, array $expected) {
    $result = judge([], $punches);

    expect(array_intersect_key($result->toArray(), $expected))->toEqual($expected);
})->with([
    'on time, full day' => [sampleDay(), ['status' => 'present', 'worked_minutes' => 540, 'late_minutes' => 0, 'overtime_minutes' => 60, 'regular_minutes' => 480, 'approved_overtime_minutes' => 60]],
    'thirty late' => [sampleDay('08:30'), ['status' => 'late', 'late_minutes' => 30, 'worked_minutes' => 510, 'overtime_minutes' => 30]],
    'left early' => [sampleDay('08:00', '16:00'), ['status' => 'undertime', 'undertime_minutes' => 60, 'overtime_minutes' => 0]],
    'lunch punched' => [sampleDay('08:00', '17:00', '12:00', '13:00'), ['worked_minutes' => 480, 'break_minutes' => 60, 'overtime_minutes' => 0]],
    'still clocked in' => [[['clock_in', '08:00']], ['status' => 'incomplete', 'worked_minutes' => 0]],
    'a minute early, seconds do not count' => [sampleDay('07:59'), ['late_minutes' => 0, 'worked_minutes' => 541]],
]);

test('a day with no punches is judged by what the day is, not by the policy', function () {
    $strict = ['undertime' => ['minimum_minutes_for_present' => 60]];

    expect(judge($strict, [])->status)->toBe('absent')
        ->and(judge($strict, [], context: ['onApprovedLeave' => true])->status)->toBe('on_leave')
        ->and(judge($strict, [], ['isWorkingDay' => false, 'requiredMinutes' => 0, 'shift' => null])->status)->toBe('day_off')
        ->and(judge($strict, [], ['holidayType' => 'regular'])->status)->toBe('holiday');
});

// ── Rounding ─────────────────────────────────────────────────────────────────

test('rounding moves the judged times, in each mode and unit', function (string $mode, int $unit, string $applyTo, string $in, string $out, int $late, int $undertime, int $worked) {
    $result = judge(['rounding' => ['mode' => $mode, 'unit' => $unit, 'apply_to' => $applyTo]], sampleDay($in, $out));

    expect($result->lateMinutes)->toBe($late)
        ->and($result->undertimeMinutes)->toBe($undertime)
        ->and($result->workedMinutes)->toBe($worked);
})->with([
    'nearest 15, both' => ['nearest', 15, 'both', '08:07', '16:53', 0, 0, 540],
    'nearest 15 rounds 08:08 up' => ['nearest', 15, 'both', '08:08', '17:00', 15, 0, 525],
    'up 15 on the way in' => ['up', 15, 'in', '08:01', '17:00', 15, 0, 525],
    'down 15 on the way out' => ['down', 15, 'out', '08:00', '16:59', 0, 15, 525],
    'in only leaves the clock-out raw' => ['nearest', 15, 'in', '08:07', '16:53', 0, 7, 533],
    'out only leaves the clock-in raw' => ['nearest', 15, 'out', '08:07', '16:53', 7, 0, 533],
    'nearest 5' => ['nearest', 5, 'both', '08:02', '17:03', 0, 0, 545],
    'nearest 10' => ['nearest', 10, 'both', '08:06', '17:00', 10, 0, 530],
    'down 30' => ['down', 30, 'both', '08:29', '17:29', 0, 0, 540],
]);

test('rounding never changes what was punched', function () {
    $result = judge(['rounding' => ['mode' => 'nearest', 'unit' => 15, 'apply_to' => 'both']], sampleDay('08:07', '17:52'));

    expect(CarbonImmutable::instance($result->firstInAt)->setTimezone(SAMPLE_ZONE)->format('H:i'))->toBe('08:07')
        ->and(CarbonImmutable::instance($result->lastOutAt)->setTimezone(SAMPLE_ZONE)->format('H:i'))->toBe('17:52')
        ->and($result->workedMinutes)->toBe(585);
});

test('rounding is read on the local clock, not on UTC', function () {
    // Kathmandu keeps UTC+05:45, so its half hours fall a quarter past UTC's.
    $result = judge(
        ['rounding' => ['mode' => 'nearest', 'unit' => 30, 'apply_to' => 'in']],
        [['clock_in', '08:07'], ['clock_out', '17:00']],
        context: ['timezone' => 'Asia/Kathmandu'],
    );

    // The sample's instants are Manila readings: 08:07 there is 00:07Z, which is
    // 05:52 in Kathmandu and rounds to 06:00 local (00:15Z) — not to 00:00Z, as
    // rounding on UTC would. So the day is judged from 08:15 Manila.
    expect($result->workedMinutes)->toBe(525)
        ->and($result->lateMinutes)->toBe(15);
});

// ── Grace and lateness ───────────────────────────────────────────────────────

test('grace per day forgives up to its minutes, every day', function () {
    $result = judge(['lateness' => ['grace_minutes' => 10]], sampleDay('08:12'));

    expect($result->lateMinutes)->toBe(2)
        ->and($result->excusedLateMinutes)->toBe(10)
        ->and($result->status)->toBe('late');
});

test('a policy grace overrides the schedule, and an unset one defers to it', function () {
    expect(judge([], sampleDay('08:12'), ['graceMinutes' => 15])->lateMinutes)->toBe(0)
        ->and(judge(['lateness' => ['grace_minutes' => 5]], sampleDay('08:12'), ['graceMinutes' => 15])->lateMinutes)->toBe(7);
});

test('a monthly allowance forgives from one pool until it runs out', function (int $usedBefore, int $late, int $excused) {
    $result = judge(
        ['lateness' => ['grace_mode' => 'monthly_allowance', 'monthly_grace_minutes' => 30]],
        sampleDay('08:10'),
        context: ['monthExcusedLateMinutesBefore' => $usedBefore],
    );

    expect($result->lateMinutes)->toBe($late)
        ->and($result->excusedLateMinutes)->toBe($excused);
})->with([
    'first late day' => [0, 0, 10],
    'third late day' => [20, 0, 10],
    'fourth late day exceeds it' => [30, 10, 0],
    'the day that straddles it' => [25, 5, 5],
]);

test('a policy that does not judge lateness never marks anybody late', function () {
    $result = judge(['lateness' => ['enabled' => false]], sampleDay('10:00', '19:00'));

    expect($result->lateMinutes)->toBe(0)
        ->and($result->status)->toBe('present');
});

test('very late is a half day, and later still an absence', function (string $in, string $status, array $flags) {
    $result = judge(['lateness' => ['half_day_after_minutes' => 120, 'absent_after_minutes' => 240]], sampleDay($in));

    expect($result->status)->toBe($status)
        ->and($result->flags)->toContain(...$flags);
})->with([
    'two hours late is still late' => ['10:00', 'late', ['late']],
    'past two hours is a half day' => ['10:01', 'half_day', ['late', 'half_day']],
    'past four hours is an absence' => ['12:01', 'absent', ['late', 'late_absent']],
]);

// ── Undertime ────────────────────────────────────────────────────────────────

test('a short day is a half day, and a very short one an absence', function (string $out, string $status, ?string $flag) {
    $result = judge(['undertime' => ['half_day_below_minutes' => 300, 'minimum_minutes_for_present' => 60]], sampleDay('08:00', $out));

    expect($result->status)->toBe($status);

    if ($flag !== null) {
        expect($result->flags)->toContain($flag);
    }
})->with([
    'five hours is undertime' => ['13:00', 'undertime', 'undertime'],
    'under five hours is a half day' => ['12:59', 'half_day', 'half_day'],
    'under an hour is an absence' => ['08:59', 'absent', 'below_minimum'],
]);

test('undertime judged on hours ignores when the day ended', function () {
    // In at 10:00 and out at 18:00 is the full eight hours, so nothing is owed on
    // hours; on the schedule's basis, leaving at 16:00 owes the hour to 17:00.
    $byHours = judge(['lateness' => ['enabled' => false], 'undertime' => ['basis' => 'hours']], sampleDay('10:00', '18:00'));
    $bySchedule = judge(['lateness' => ['enabled' => false]], sampleDay('10:00', '16:00'));

    expect($byHours->undertimeMinutes)->toBe(0)
        ->and($byHours->status)->toBe('present')
        ->and($bySchedule->undertimeMinutes)->toBe(60);
});

// ── Breaks ───────────────────────────────────────────────────────────────────

test('an unpunched break is deducted only when none was punched and the day ran long enough', function (array $punches, int $worked, int $break, bool $deducted) {
    $result = judge(['breaks' => ['auto_deduct_minutes' => 60, 'auto_deduct_after_worked_minutes' => 300]], $punches);

    expect($result->workedMinutes)->toBe($worked)
        ->and($result->breakMinutes)->toBe($break)
        ->and(in_array('break_deducted', $result->flags, true))->toBe($deducted);
})->with([
    'no break punched' => [sampleDay(), 480, 60, true],
    'a break was punched' => [sampleDay('08:00', '17:00', '12:00', '12:30'), 510, 30, false],
    'too short a day to owe one' => [sampleDay('08:00', '12:00'), 240, 0, false],
    'still clocked in' => [[['clock_in', '08:00']], 0, 0, false],
]);

test('an over-long break is flagged and owed like leaving early', function () {
    $result = judge(['breaks' => ['max_break_minutes' => 60]], sampleDay('08:00', '17:00', '12:00', '13:30'));

    expect($result->breakMinutes)->toBe(90)
        ->and($result->undertimeMinutes)->toBe(30)
        ->and($result->flags)->toContain('break_exceeded', 'undertime')
        ->and($result->status)->toBe('undertime');
});

test('the paid part of a punched break counts as worked', function () {
    $result = judge(['breaks' => ['paid_break_minutes' => 15]], sampleDay('08:00', '17:00', '10:00', '10:30'));

    expect($result->workedMinutes)->toBe(525)
        ->and($result->breakMinutes)->toBe(30);
});

// ── Overtime ─────────────────────────────────────────────────────────────────

test('overtime follows the policy basis without counting a minute twice', function (array $overtime, array $punches, int $weekBefore, int $expected) {
    $result = judge(['overtime' => $overtime], $punches, context: ['weekRegularMinutesBefore' => $weekBefore]);

    expect($result->overtimeMinutes)->toBe($expected)
        ->and($result->regularMinutes + $result->overtimeMinutes)->toBe($result->workedMinutes);
})->with([
    'daily, after the required hours' => [['basis' => 'daily'], sampleDay('08:00', '19:00'), 0, 180],
    'daily, after its own threshold' => [['basis' => 'daily', 'daily_after_minutes' => 600], sampleDay('08:00', '19:00'), 0, 60],
    'none at all' => [['basis' => 'none'], sampleDay('08:00', '19:00'), 0, 0],
    'weekly, under the threshold' => [['basis' => 'weekly', 'weekly_after_minutes' => 2400], sampleDay('08:00', '19:00'), 1200, 0],
    'weekly, crossing it today' => [['basis' => 'weekly', 'weekly_after_minutes' => 2400], sampleDay('08:00', '17:00'), 2280, 420],
    'weekly, already past it' => [['basis' => 'weekly', 'weekly_after_minutes' => 2400], sampleDay('08:00', '12:00'), 2400, 240],
    'both, daily first then the week' => [['basis' => 'daily_and_weekly', 'daily_after_minutes' => 480, 'weekly_after_minutes' => 2400], sampleDay('08:00', '18:00'), 2000, 200],
    'both, the week not yet reached' => [['basis' => 'daily_and_weekly', 'daily_after_minutes' => 480, 'weekly_after_minutes' => 2400], sampleDay('08:00', '18:00'), 1000, 120],
    'below the minimum block' => [['basis' => 'daily', 'daily_after_minutes' => 540, 'min_block_minutes' => 30], sampleDay('08:00', '17:20'), 0, 0],
    'at the minimum block' => [['basis' => 'daily', 'daily_after_minutes' => 540, 'min_block_minutes' => 30], sampleDay('08:00', '17:30'), 0, 30],
]);

test('an early clock-in is not worked when the policy does not count it', function () {
    $counted = judge([], sampleDay('07:00', '17:00'));
    $clipped = judge(['overtime' => ['count_early_clock_in' => false]], sampleDay('07:00', '17:00'));

    expect($counted->workedMinutes)->toBe(600)
        ->and($counted->overtimeMinutes)->toBe(120)
        ->and($clipped->workedMinutes)->toBe(540)
        ->and($clipped->overtimeMinutes)->toBe(60)
        ->and($clipped->lateMinutes)->toBe(0);
});

test('overtime that needs approval is computed but not approved', function () {
    $result = judge(['overtime' => ['requires_approval' => true]], sampleDay('08:00', '19:00'));

    expect($result->overtimeMinutes)->toBe(180)
        ->and($result->approvedOvertimeMinutes)->toBe(0)
        ->and($result->flags)->toContain('unapproved_overtime');

    expect(judge([], sampleDay('08:00', '19:00'))->approvedOvertimeMinutes)->toBe(180);
});

// ── Rest days and holidays ───────────────────────────────────────────────────

test('rest-day minutes are bucketed, and overtime on them is the policy choice', function (array $overtime, int $expected) {
    $result = judge(['overtime' => $overtime], sampleDay('08:00', '17:00'), ['isWorkingDay' => false, 'requiredMinutes' => 0, 'shift' => null]);

    expect($result->restDayMinutes)->toBe(540)
        ->and($result->overtimeMinutes)->toBe($expected)
        ->and($result->flags)->toContain('rest_day_worked')
        ->and($result->status)->toBe('present');
})->with([
    'nothing is required, so all of it is beyond' => [[], 540],
    'regular up to eight hours' => [['daily_after_minutes' => 480], 60],
    'all overtime, whatever the threshold' => [['daily_after_minutes' => 480, 'rest_day_all_overtime' => true], 540],
]);

test('holiday minutes are bucketed on a non-working holiday only', function (string $type, bool $allOvertime, int $holiday, int $overtime) {
    $result = judge(
        ['overtime' => ['daily_after_minutes' => 480, 'holiday_all_overtime' => $allOvertime]],
        sampleDay('08:00', '17:00'),
        ['holidayType' => $type],
    );

    expect($result->holidayMinutes)->toBe($holiday)
        ->and($result->overtimeMinutes)->toBe($overtime);
})->with([
    'regular holiday' => ['regular', false, 540, 60],
    'regular holiday, all overtime' => ['regular', true, 540, 540],
    'special non-working day' => ['special_non_working', false, 540, 60],
    'a special working day is an ordinary day' => ['special_working', true, 0, 60],
]);

// ── Night differential ───────────────────────────────────────────────────────

test('night minutes are the worked minutes inside the window, across midnight', function (array $punches, array $shift, int $night) {
    $result = judge(['night' => ['enabled' => true, 'start' => '22:00', 'end' => '06:00']], $punches, ['shift' => $shift]);

    expect($result->nightMinutes)->toBe($night);
})->with([
    'a whole night shift' => [sampleDay('22:00', '06:00'), ['22:00', '06:00'], 480],
    'an evening that runs into it' => [sampleDay('14:00', '23:30'), ['14:00', '23:00'], 90],
    'either side of midnight' => [sampleDay('20:00', '02:00'), ['20:00', '02:00'], 240],
    'an early start before six' => [sampleDay('05:00', '14:00'), ['05:00', '14:00'], 60],
    'a day shift has none' => [sampleDay(), ['08:00', '17:00'], 0],
    'a break inside the window is not night work' => [sampleDay('22:00', '06:00', '01:00', '02:00'), ['22:00', '06:00'], 420],
]);

test('night minutes are not counted when the policy has none', function () {
    expect(judge([], sampleDay('22:00', '06:00'), ['shift' => ['22:00', '06:00']])->nightMinutes)->toBe(0);
});

// ── Presets and snapshots ────────────────────────────────────────────────────

test('the Philippine preset judges a long day the way its card says', function () {
    $result = judge(
        AttendancePolicyPresets::find('ph_labor_code')['settings'],
        sampleDay('08:00', '19:00'),
    );

    // 11 hours on the clock, the hour's lunch nobody punched taken off, eight
    // regular, two over — which waits for approval.
    expect($result->workedMinutes)->toBe(600)
        ->and($result->breakMinutes)->toBe(60)
        ->and($result->regularMinutes)->toBe(480)
        ->and($result->overtimeMinutes)->toBe(120)
        ->and($result->approvedOvertimeMinutes)->toBe(0)
        ->and($result->flags)->toContain('break_deducted', 'unapproved_overtime');
});

test('older snapshots still evaluate, as the built-in fallback', function (int $version) {
    $snapshot = ['version' => $version, 'grace_minutes' => 5, 'required_minutes' => 480, 'is_working_day' => true];

    if ($version === 2) {
        $snapshot += ['type' => 'fixed', 'segments' => [['start' => '08:00', 'end' => '17:00']], 'source' => 'assignment'];
    }

    $rules = DayRules::fromArray($snapshot);

    expect($rules->policy)->toEqual(AttendancePolicySettings::fallback())
        ->and($rules->policyName)->toBeNull()
        ->and($rules->graceMinutes())->toBe(5)
        ->and(judge([], sampleDay('08:10'), ['graceMinutes' => 5])->lateMinutes)->toBe(5);
})->with([1, 2]);

test('a version 3 snapshot carries the policy it was judged by', function () {
    $rules = DayRules::fromArray([
        'version' => 3,
        'grace_minutes' => 0,
        'required_minutes' => 480,
        'is_working_day' => true,
        'policy' => ['id' => 7, 'name' => 'Shift work', 'source' => 'schedule', 'settings' => ['rounding' => ['mode' => 'nearest', 'unit' => 15]]],
    ]);

    expect($rules->policyId)->toBe(7)
        ->and($rules->policyName)->toBe('Shift work')
        ->and($rules->policySource)->toBe('schedule')
        ->and($rules->policy->roundingMode)->toBe('nearest')
        ->and($rules->toArray()['version'])->toBe(3)
        ->and($rules->toArray()['policy']['settings']['rounding']['unit'])->toBe(15);
});

test('settings read back complete, whatever was stored', function () {
    $settings = AttendancePolicySettings::fromArray([
        'rounding' => ['mode' => 'sideways', 'unit' => 7],
        'overtime' => ['daily_after_minutes' => '600'],
        'capture' => ['allowed_sources' => ['mobile', 'fax']],
    ]);

    expect($settings->roundingMode)->toBe('none')
        ->and($settings->roundingUnit)->toBe(15)
        ->and($settings->overtimeDailyAfterMinutes)->toBe(600)
        ->and($settings->allowedSources)->toBe(['mobile'])
        ->and(AttendancePolicySettings::fromArray($settings->toArray()))->toEqual($settings);
});

// ── Every seeded day, judged as it was ───────────────────────────────────────

/**
 * The calculator as it stood before ADR 0038, verbatim in its arithmetic — the
 * reference the built-in fallback has to reproduce.
 *
 * @param  Collection<int, AttendancePunch>  $punches
 * @return array<string, int|string>
 */
function prePolicyVerdict(AttendanceRecord $record, Collection $punches, DayRules $rules, bool $onLeave): array
{
    $punches = $punches->sortBy(['punched_at', 'id'])->values();
    $minutes = fn ($a, $b): int => max(0, intdiv($b->getTimestamp() - $a->getTimestamp(), 60));

    $firstIn = $punches->firstWhere('type', 'clock_in')?->punched_at;
    $lastOut = $punches->where('type', 'clock_out')->last()?->punched_at;

    [$worked, $break, $prev, $onClock, $onBreak] = [0, 0, null, false, false];

    foreach ($punches as $punch) {
        if ($prev !== null) {
            $m = $minutes($prev, $punch->punched_at);
            if ($onClock && $onBreak) {
                $break += $m;
            } elseif ($onClock) {
                $worked += $m;
            }
        }
        match ($punch->type) {
            'clock_in' => $onClock = true,
            'clock_out' => [$onClock, $onBreak] = [false, false],
            'break_start' => $onBreak = true,
            'break_end' => $onBreak = false,
        };
        $prev = $punch->punched_at;
    }

    $late = 0;
    if ($firstIn !== null && $rules->type !== 'hours_only') {
        $against = $rules->type === 'flexible' ? $rules->coreStartAt : $record->scheduled_start_at;
        $late = $against === null ? 0 : max(0, intdiv($firstIn->getTimestamp() - ($against->getTimestamp() + $rules->graceMinutes * 60), 60));
    }

    $undertime = 0;
    if ($lastOut !== null) {
        $short = max(0, $rules->requiredMinutes - $worked);
        $end = $record->scheduled_end_at;
        $undertime = match ($rules->type) {
            'hours_only' => $short,
            'flexible' => max($rules->coreEndAt !== null && $lastOut->lt($rules->coreEndAt) ? $minutes($lastOut, $rules->coreEndAt) : 0, $short),
            default => $end !== null && $lastOut->lt($end) ? $minutes($lastOut, $end) : 0,
        };
    }

    $status = match (true) {
        $punches->isEmpty() => AttendanceCalculator::noPunchStatus($rules, $onLeave),
        $firstIn !== null && $lastOut === null => 'incomplete',
        $late > 0 => 'late',
        $undertime > 0 => 'undertime',
        default => 'present',
    };

    return [
        'status' => $status,
        'worked' => $worked,
        'break' => $break,
        'late' => $late,
        'undertime' => $undertime,
        'overtime' => max(0, $worked - $rules->requiredMinutes),
    ];
}

test('the built-in fallback reproduces the pre-policy verdict on every seeded day', function () {
    $this->seed(DatabaseSeeder::class);

    $checked = 0;

    foreach (Organization::orderBy('id')->get() as $organization) {
        app(Tenancy::class)->runFor($organization, function () use (&$checked): void {
            AttendanceRecord::query()->with('punches')->orderBy('id')->each(function (AttendanceRecord $record) use (&$checked): void {
                $rules = DayRules::fromArray($record->rules);
                $onLeave = $record->status === 'on_leave';

                // A seeded day is judged by the fallback: no company has a policy.
                expect($rules->policyId)->toBeNull();

                $reference = prePolicyVerdict($record, $record->punches, $rules, $onLeave);

                expect([
                    'status' => $record->status,
                    'worked' => $record->worked_minutes,
                    'break' => $record->break_minutes,
                    'late' => $record->late_minutes,
                    'undertime' => $record->undertime_minutes,
                    'overtime' => $record->overtime_minutes,
                ])->toBe($reference, "Day {$record->work_date->toDateString()} of employee {$record->employee_id} moved");

                expect($record->regular_minutes + $record->overtime_minutes)->toBe($record->worked_minutes)
                    ->and($record->approved_overtime_minutes)->toBe($record->overtime_minutes);

                $checked++;
            });
        });
    }

    expect($checked)->toBeGreaterThan(100);
});

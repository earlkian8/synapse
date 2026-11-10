<?php

namespace App\Support\CheckIns;

use App\Models\CheckIn;
use App\Models\Employee;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;

/**
 * The canonical operations behind answering a check-in (ADR 0102): what the person
 * sees, and saving their answers. The web page and the mobile API both call this,
 * so the rules live once.
 *
 * One check-in a month: answering again in the same month changes that month's
 * answers rather than adding a second. A past month is never changed.
 *
 * **Not activity-logged, on purpose.** The log is read by administrators, and "Jane
 * checked in on the 3rd" is itself something about Jane. Who answered is as
 * confidential as what they answered (ADR 0102); only turning check-ins on or off is
 * logged.
 */
final class CheckInWorkflow
{
    /** The months of their own history a person is shown. */
    public const HISTORY_MONTHS = 12;

    public function __construct(private readonly Tenancy $tenancy) {}

    public function enabled(): bool
    {
        return (bool) $this->tenancy->organization()?->check_ins_enabled;
    }

    /**
     * Save this month's answers, or change them.
     *
     * @param  array<string, int|string|null>  $answers  every key of {@see CheckInQuestions::keys()}, and `note`
     *
     * @throws CheckInException
     */
    public function submit(Employee $employee, array $answers): CheckIn
    {
        if (! $this->enabled()) {
            throw new CheckInException('Check-ins are turned off in this workspace.');
        }

        if ($employee->employment_status !== 'active') {
            throw new CheckInException('Only people currently employed here answer check-ins.');
        }

        $values = collect(CheckInQuestions::keys())->mapWithKeys(fn (string $key): array => [$key => (int) $answers[$key]])->all();
        $note = trim((string) ($answers['note'] ?? ''));

        return CheckIn::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'period' => CheckInPeriod::current()->toDateString()],
            $values + [
                'department_id' => $employee->department_id,
                'note' => $note === '' ? null : $note,
                'submitted_at' => now(),
            ],
        );
    }

    /**
     * What the person sees: whether this month's is open, their answers so far, and
     * their own months, newest first.
     *
     * @return array<string, mixed>
     */
    public function overview(?Employee $employee): array
    {
        $period = CheckInPeriod::current();
        $history = $employee === null ? collect() : CheckIn::query()
            ->where('employee_id', $employee->id)
            ->where('period', '>=', $period->subMonthsNoOverflow(self::HISTORY_MONTHS - 1)->toDateString())
            ->orderByDesc('period')
            ->get();
        $current = $history->first(fn (CheckIn $c): bool => $c->period->equalTo($period));

        return [
            'enabled' => $this->enabled(),
            'has_employee' => $employee !== null,
            'period' => $period->toDateString(),
            'period_label' => CheckInPeriod::label($period),
            'days_left' => CheckInPeriod::daysLeft(),
            'current' => $current ? CheckInQuestions::present($current) : null,
            'history' => $history->map(fn (CheckIn $c): array => CheckInQuestions::present($c))->values()->all(),
            'months' => array_map(fn (CarbonImmutable $p): string => $p->toDateString(), array_reverse(CheckInPeriod::recent(self::HISTORY_MONTHS))),
            'questions' => CheckInQuestions::all(),
        ];
    }

    /** Whether this person still has this month's to answer. */
    public function due(?Employee $employee): bool
    {
        return $employee !== null
            && $employee->employment_status === 'active'
            && $this->enabled()
            && ! CheckIn::query()->where('employee_id', $employee->id)->whereDate('period', CheckInPeriod::current())->exists();
    }
}

<?php

namespace App\Support\CheckIns;

use App\Models\CheckIn;
use App\Models\Employee;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;

/**
 * The two nudges a month (ADR 0102): on the 1st, that the month's check-in is open;
 * on the {@see self::SECOND_NUDGE_DAY}th, to whoever has not answered yet. Both by
 * the organisation's own date, so each goes once whatever time the daily job runs.
 * Nobody is told who has or hasn't answered; each person only hears about their own.
 */
final class CheckInReminders
{
    public const SECOND_NUDGE_DAY = 20;

    public function __construct(private readonly Tenancy $tenancy) {}

    /** Send today's nudges for the bound organisation; returns how many were sent. */
    public function sendDue(): int
    {
        if (! $this->tenancy->organization()?->check_ins_enabled) {
            return 0;
        }

        $today = CarbonImmutable::parse(OrganizationClock::today());

        if (! in_array($today->day, [1, self::SECOND_NUDGE_DAY], true)) {
            return 0;
        }

        $period = CheckInPeriod::current();
        $label = $period->format('F');
        $answered = CheckIn::query()->whereDate('period', $period)->pluck('employee_id');
        $people = Employee::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $answered)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($people as $employee) {
            if (! $employee->user->can('check-ins.respond')) {
                continue;
            }

            $sent += Notifier::toUser(
                $employee->user,
                $today->day === 1 ? "Your {$label} check-in is open" : "A minute for your {$label} check-in?",
                'Six quick questions about how work is going. Your answers are confidential: no one at the company sees them, only results for groups of five or more.',
                url: '/check-ins/me',
                category: 'check-ins',
            );
        }

        return $sent;
    }
}

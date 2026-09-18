<?php

namespace App\Support\Attendance;

use App\Models\AttendancePeriod;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;

/**
 * Lays out the periods attendance closes on (ADR 0039), on the organisation's
 * calendar and its `attendance_period_frequency`:
 *
 *  - `weekly` — Monday to Sunday.
 *  - `bi_weekly` — fourteen days, the first starting on a Monday.
 *  - `semi_monthly` — the 1st to the 15th, the 16th to the month's end (the
 *    Philippine cut-off, and the default).
 *  - `monthly` — the calendar month.
 *
 * Periods are **contiguous and never overlap**: each new one starts the day after
 * the last one ends, and ends at the next boundary of the current frequency. So
 * a company that changes frequency mid-month gets one short period that carries
 * it to the new calendar, rather than a gap or two periods claiming one day.
 */
class PeriodCalendar
{
    /**
     * Make sure the period covering the organisation's today, and the one after
     * it, exist. Returns how many were created. Idempotent.
     *
     * A company's first periods start one period back, so the period that has
     * just ended — the one payroll is about to run on — can be locked too.
     */
    public function ensureCurrent(): int
    {
        $today = CarbonImmutable::parse(OrganizationClock::today());
        $created = 0;

        // Generated up to the end of the period after today's.
        while (true) {
            $last = AttendancePeriod::query()->orderByDesc('end_date')->first();

            if ($last !== null && $last->end_date->toDateString() >= $today->toDateString()) {
                $current = AttendancePeriod::query()
                    ->whereDate('start_date', '<=', $today->toDateString())
                    ->whereDate('end_date', '>=', $today->toDateString())
                    ->first();

                // Today's exists; stop once one more lies beyond it.
                if ($current !== null && $last->id !== $current->id) {
                    return $created;
                }
            }

            $start = $last !== null
                ? CarbonImmutable::parse($last->end_date->toDateString())->addDay()
                : $this->previousStart($today);

            AttendancePeriod::create([
                'start_date' => $start->toDateString(),
                'end_date' => $this->endFrom($start)->toDateString(),
                'status' => 'open',
            ]);

            $created++;
        }
    }

    /**
     * The organisation's frequency.
     */
    public function frequency(): string
    {
        $frequency = app(Tenancy::class)->organization()?->attendance_period_frequency;

        return in_array($frequency, AttendancePeriod::FREQUENCIES, true) ? $frequency : 'semi_monthly';
    }

    /**
     * Where the period containing a date starts, for a company with no periods yet.
     */
    public function boundaryStart(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this->frequency()) {
            'weekly', 'bi_weekly' => $date->startOfWeek(CarbonImmutable::MONDAY),
            'monthly' => $date->startOfMonth(),
            default => $date->day <= 15 ? $date->startOfMonth() : $date->startOfMonth()->addDays(15),
        };
    }

    /**
     * Where the period before the one containing a date starts — a company's
     * first period.
     */
    public function previousStart(CarbonImmutable $date): CarbonImmutable
    {
        $current = $this->boundaryStart($date);

        // A fortnight has no calendar boundary to step back to; it is two weeks.
        return $this->frequency() === 'bi_weekly'
            ? $current->subDays(14)
            : $this->boundaryStart($current->subDay());
    }

    /**
     * The last day of a period starting on `$start`: the next boundary of the
     * frequency on or after it.
     */
    public function endFrom(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this->frequency()) {
            'weekly' => $start->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
            'bi_weekly' => $start->addDays(13),
            'monthly' => $start->endOfMonth()->startOfDay(),
            default => $start->day <= 15 ? $start->startOfMonth()->addDays(14) : $start->endOfMonth()->startOfDay(),
        };
    }
}

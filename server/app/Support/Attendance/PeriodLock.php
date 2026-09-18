<?php

namespace App\Support\Attendance;

use App\Models\AttendancePeriod;
use App\Support\Tenancy;
use Illuminate\Support\Collection;

/**
 * The one guard between a locked attendance period and anything that would
 * change a day inside it (ADR 0039).
 *
 * The engine calls it, not the controllers: {@see AttendanceClock} before a
 * punch, a manual edit, a correction, a sign-off, a re-apply or a delete;
 * {@see AttendanceRequestApprover} before a decision; `attendance:recompute`
 * before each day. So there is one place that says no, and no path that forgot
 * to ask.
 *
 * Two kinds of question, answered two ways:
 *
 *  - {@see assertOpen()} is asked before a write and always reads the database,
 *    so a period locked a moment ago is honoured.
 *  - {@see isLocked()} is asked while *displaying* many days — the board, a
 *    history, the API — and reads the organisation's locked ranges once per
 *    request (the container keeps one instance per request).
 */
class PeriodLock
{
    /** @var array<int, Collection<int, AttendancePeriod>> The locked periods, per organisation. */
    private array $ranges = [];

    /**
     * Refuse when a work date is inside a locked period.
     *
     * @throws AttendanceLockedException
     */
    public function assertOpen(string $date): void
    {
        $period = $this->lockedPeriodFor($date);

        if ($period !== null) {
            throw AttendanceLockedException::forDate($date, $period->label());
        }
    }

    /**
     * Refuse when any date in [from, to] is inside a locked period.
     *
     * @throws AttendanceLockedException
     */
    public function assertRangeOpen(string $from, string $to): void
    {
        $period = AttendancePeriod::query()
            ->where('status', 'locked')
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->orderBy('start_date')
            ->first();

        if ($period !== null) {
            throw AttendanceLockedException::forDate(max($from, $period->start_date->toDateString()), $period->label());
        }
    }

    /**
     * The locked period a work date falls in, read fresh.
     */
    public function lockedPeriodFor(string $date): ?AttendancePeriod
    {
        return AttendancePeriod::query()
            ->where('status', 'locked')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    /**
     * Whether a work date is inside a locked period — for display, from the
     * ranges read once per request.
     */
    public function isLocked(string $date): bool
    {
        return $this->ranges()->contains(fn (AttendancePeriod $period): bool => $period->covers($date));
    }

    /**
     * The locked period a date is in, for display ("Locked · Sep 1 – 15").
     */
    public function periodFor(string $date): ?AttendancePeriod
    {
        return $this->ranges()->first(fn (AttendancePeriod $period): bool => $period->covers($date));
    }

    /**
     * Every locked period of the current organisation, read once per request.
     *
     * @return Collection<int, AttendancePeriod>
     */
    public function ranges(): Collection
    {
        $key = (int) app(Tenancy::class)->id();

        return $this->ranges[$key] ??= AttendancePeriod::query()
            ->where('status', 'locked')
            ->orderBy('start_date')
            ->get(['id', 'organization_id', 'start_date', 'end_date', 'status']);
    }

    /**
     * Forget what was read — after a period locks or unlocks.
     */
    public function flush(): void
    {
        $this->ranges = [];
    }
}

<?php

namespace App\Support\Attendance;

use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;

/**
 * What one person is due to work on one date — the answer
 * {@see ShiftResolver} gives, and everything attendance needs to judge the day
 * (ADR 0037).
 *
 * Clock-face times (`segments`, `coreStart`, …) are how the schedule is written;
 * the instants beside them ({@see instants()}, {@see startsAt()}) are those
 * readings turned into moments on the organisation's clock, with each segment
 * rolled forward when it lands before the one before it — so a 22:00–06:00 shift
 * ends the next morning and an evening half of a split shift stays after the
 * morning half.
 *
 * `source` records *why* this shift applies, so the roster can say so.
 */
final readonly class ResolvedShift
{
    /** Where a shift came from, most specific first. */
    public const SOURCES = ['roster', 'assignment', 'employee', 'department', 'organization', 'fallback'];

    /**
     * @param  list<array{start: string, end: string}>  $segments  Clock-face "HH:MM" pairs, in order.
     */
    public function __construct(
        public string $date,
        public string $type,
        public bool $isWorkingDay,
        public array $segments,
        public int $requiredMinutes,
        public int $graceMinutes,
        public int $unpaidBreakMinutes,
        public string $source,
        public ?string $coreStart = null,
        public ?string $coreEnd = null,
        public ?string $earliestStart = null,
        public ?string $latestEnd = null,
        public ?int $scheduleId = null,
        public ?string $scheduleName = null,
        public ?int $cycleOffset = null,
        public ?int $dayIndex = null,
    ) {}

    /**
     * The shift a tenant with nothing configured works: Mon–Fri, 08:00–17:00,
     * eight hours — what the module did before schedules could be configured, so
     * an unconfigured organisation keeps the numbers it had.
     */
    public static function fallback(string $date): self
    {
        $weekday = CarbonImmutable::parse($date)->isoWeekday();
        $working = $weekday <= 5;

        return new self(
            date: $date,
            type: 'fixed',
            isWorkingDay: $working,
            segments: $working ? [['start' => '08:00', 'end' => '17:00']] : [],
            requiredMinutes: DayRules::DEFAULT_REQUIRED_MINUTES,
            graceMinutes: 0,
            unpaidBreakMinutes: 0,
            source: 'fallback',
            dayIndex: $weekday,
        );
    }

    /**
     * The segments as ordered [start, end] instants on the organisation's clock.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function instants(): array
    {
        $out = [];
        $floor = null;

        foreach ($this->segments as $segment) {
            $date = $this->date;
            $start = OrganizationClock::at($date, $segment['start']);

            // A segment that would begin before the previous one ended belongs to
            // the next day: the second half of a split shift that runs past
            // midnight, or a rotation's late start.
            if ($floor !== null && $start->lt($floor)) {
                $date = CarbonImmutable::parse($date)->addDay()->toDateString();
                $start = OrganizationClock::at($date, $segment['start']);
            }

            $end = OrganizationClock::at($date, $segment['end']);

            if ($end->lte($start)) {
                $end = OrganizationClock::at(CarbonImmutable::parse($date)->addDay()->toDateString(), $segment['end']);
            }

            $out[] = [$start, $end];
            $floor = $end;
        }

        return $out;
    }

    /**
     * When the shift begins — the first segment's start.
     */
    public function startsAt(): ?CarbonImmutable
    {
        $instants = $this->instants();

        return $instants === [] ? null : $instants[0][0];
    }

    /**
     * When the shift ends — the last segment's end, which may be the next morning.
     */
    public function endsAt(): ?CarbonImmutable
    {
        $instants = $this->instants();

        return $instants === [] ? null : $instants[array_key_last($instants)][1];
    }

    /**
     * The first segment's start as a clock-face "HH:MM", for the record's display
     * columns and the setup screens.
     */
    public function startTime(): ?string
    {
        return $this->segments === [] ? null : $this->segments[0]['start'];
    }

    /**
     * The last segment's end as a clock-face "HH:MM".
     */
    public function endTime(): ?string
    {
        return $this->segments === [] ? null : $this->segments[array_key_last($this->segments)]['end'];
    }

    /**
     * The window a flexible shift's worker must be present for, as instants —
     * null on any other type, or when the schedule sets no core hours.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function coreWindow(): array
    {
        if ($this->type !== 'flexible' || $this->coreStart === null || $this->coreEnd === null) {
            return [null, null];
        }

        $start = OrganizationClock::at($this->date, $this->coreStart);
        $end = OrganizationClock::at($this->date, $this->coreEnd);

        if ($end->lte($start)) {
            $end = OrganizationClock::at(CarbonImmutable::parse($this->date)->addDay()->toDateString(), $this->coreEnd);
        }

        return [$start, $end];
    }

    /**
     * The window punches are accepted in — a flexible schedule's own
     * earliest/latest, otherwise the shift itself.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function acceptWindow(): array
    {
        if ($this->type === 'flexible' && $this->earliestStart !== null && $this->latestEnd !== null) {
            $start = OrganizationClock::at($this->date, $this->earliestStart);
            $end = OrganizationClock::at($this->date, $this->latestEnd);

            if ($end->lte($start)) {
                $end = OrganizationClock::at(CarbonImmutable::parse($this->date)->addDay()->toDateString(), $this->latestEnd);
            }

            return [$start, $end];
        }

        return [$this->startsAt(), $this->endsAt()];
    }

    /**
     * The shift as the roster and the day modal show it: "08:00–12:00 · 13:00–17:00",
     * or why there are no hours.
     */
    public function label(): string
    {
        if (! $this->isWorkingDay || $this->segments === []) {
            return $this->type === 'hours_only' && $this->isWorkingDay ? $this->hoursLabel() : 'Rest day';
        }

        if ($this->type === 'hours_only') {
            return $this->hoursLabel();
        }

        return implode(' · ', array_map(
            fn (array $segment): string => $segment['start'].'–'.$segment['end'],
            $this->segments,
        ));
    }

    /**
     * "8h" / "7h 30m" — what an hours-only day asks for.
     */
    private function hoursLabel(): string
    {
        $hours = intdiv($this->requiredMinutes, 60);
        $minutes = $this->requiredMinutes % 60;

        return $minutes === 0 ? "{$hours}h" : "{$hours}h {$minutes}m";
    }
}

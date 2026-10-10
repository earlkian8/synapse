<?php

namespace App\Support\Events;

use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;

/**
 * The dates a repeating event falls on (ADR 0070), worked out on the office
 * clock: every occurrence starts at the first one's local time and lasts as
 * long. Daily and monthly repeat every n days or months; weekly repeats on
 * chosen weekdays every n weeks. Monthly keeps to the first date's day of the
 * month, clamped to a shorter month's last day (31 Jan → 28 Feb → 31 Mar).
 *
 * A series ends on a date (inclusive) or after a count, whichever comes
 * first, and is held to {@see self::MAX_OCCURRENCES} dates and two years — past
 * either, it is refused in words ({@see EventException}).
 */
final class Recurrence
{
    public const MAX_OCCURRENCES = 100;

    public const MAX_YEARS = 2;

    public const MAX_INTERVAL = 4;

    /**
     * @param  CarbonImmutable  $localStart  The first start, in the organisation's zone.
     * @param  int|null  $durationMinutes  Null when the event has no end.
     * @param  array{frequency: string, interval?: int|null, weekdays?: list<int>|null, until?: string|null, count?: int|null}  $rule
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable|null}> In UTC, soonest first.
     *
     * @throws EventException
     */
    public static function occurrences(CarbonImmutable $localStart, ?int $durationMinutes, array $rule): array
    {
        $interval = max(1, min(self::MAX_INTERVAL, (int) ($rule['interval'] ?? 1)));
        $count = isset($rule['count']) ? (int) $rule['count'] : null;
        $until = filled($rule['until'] ?? null)
            ? CarbonImmutable::parse($rule['until'], $localStart->getTimezone())->endOfDay()
            : null;

        if ($count === null && $until === null) {
            throw new EventException('Say when the repeat ends: on a date, or after a number of times.', 'repeat');
        }

        if ($until !== null && $until->lt($localStart)) {
            throw new EventException('The repeat has to end after the first date.', 'repeat');
        }

        if ($until !== null && $until->gt($localStart->addYears(self::MAX_YEARS)->endOfDay())) {
            throw new EventException('A repeating event can run for at most two years.', 'repeat');
        }

        $starts = [];

        foreach (self::candidates($localStart, $rule['frequency'] ?? 'weekly', $interval, $rule['weekdays'] ?? null) as $start) {
            if (($until !== null && $start->gt($until)) || ($count !== null && count($starts) >= $count)) {
                break;
            }

            if (count($starts) >= self::MAX_OCCURRENCES) {
                throw new EventException('A repeating event can have at most 100 dates. Choose an earlier end, or fewer repeats.', 'repeat');
            }

            $starts[] = $start;
        }

        return array_map(fn (CarbonImmutable $start): array => [
            'starts_at' => $start->utc(),
            'ends_at' => $durationMinutes === null ? null : $start->addMinutes($durationMinutes)->utc(),
        ], $starts);
    }

    /**
     * Every candidate start, in order, without end. Each one is built from the
     * first start's date and wall-clock time, so a zone's clock change never
     * drifts the time of day.
     *
     * @param  list<int>|null  $weekdays
     * @return \Generator<int, CarbonImmutable>
     */
    private static function candidates(CarbonImmutable $first, string $frequency, int $interval, ?array $weekdays): \Generator
    {
        $time = $first->format('H:i:s');
        $zone = $first->getTimezone();
        $at = fn (CarbonImmutable $day): CarbonImmutable => CarbonImmutable::parse($day->toDateString().' '.$time, $zone);

        if ($frequency === 'daily') {
            for ($n = 0; ; $n++) {
                yield $at($first->addDays($n * $interval));
            }
        }

        if ($frequency === 'monthly') {
            for ($n = 0; ; $n++) {
                yield $at($first->addMonthsNoOverflow($n * $interval));
            }
        }

        // Weekly: the chosen weekdays, the first date's own always among them.
        $days = collect($weekdays ?? [])
            ->map(fn ($day): int => (int) $day)
            ->push($first->dayOfWeekIso)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values();
        $monday = $first->startOfWeek(CarbonImmutable::MONDAY);

        for ($week = 0; ; $week++) {
            foreach ($days as $day) {
                $start = $at($monday->addWeeks($week * $interval)->addDays($day - 1));

                if ($start->gte($first)) {
                    yield $start;
                }
            }
        }
    }

    /**
     * A wall-clock value (form or assistant) read on the organisation's clock,
     * kept in its zone for the arithmetic above.
     */
    public static function local(string|\DateTimeInterface $value): CarbonImmutable
    {
        return OrganizationClock::local(
            $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : OrganizationClock::parse($value),
        );
    }
}

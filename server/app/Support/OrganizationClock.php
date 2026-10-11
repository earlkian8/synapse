<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * The current organisation's wall clock — the only place that knows which zone
 * the tenant keeps (ADR 0036).
 *
 * Storage stays UTC (`config('app.timezone')`), because an instant is an instant.
 * Judgement does not: "today", "late" and "which day a punch belongs to" are
 * questions asked of the clock on the office wall. Before this class every one of
 * them was answered in UTC, so an 08:30 clock-in in Manila was stored as 00:30Z,
 * compared with an "08:00" shift start read as 08:00Z, and came out early — and a
 * 07:45 clock-in was filed under the day before.
 *
 * The zone is read from the bound tenant ({@see Tenancy}), so a console command
 * that walks organisations with `Tenancy::runFor()` gets each one's clock in turn.
 * With nothing bound, the application zone is used.
 *
 * A site can keep its own clock (ADR 0079). Every method that reads the wall
 * takes an optional `$zone`, so a person based there is judged on their site's
 * clock; null (or empty) means the organisation's.
 */
class OrganizationClock
{
    /**
     * The zone a company keeps until it says otherwise — the column default the
     * existing tenants were back-filled with.
     */
    public const DEFAULT_TIMEZONE = 'Asia/Manila';

    /**
     * The organisation's IANA zone, e.g. "Asia/Manila".
     */
    public static function timezone(): string
    {
        $zone = app(Tenancy::class)->organization()?->timezone;

        return is_string($zone) && $zone !== '' ? $zone : (string) config('app.timezone', 'UTC');
    }

    /**
     * The zone to read: the one given (a site's), or the organisation's.
     */
    public static function zone(?string $zone = null): string
    {
        return is_string($zone) && $zone !== '' ? $zone : self::timezone();
    }

    /**
     * The present moment on the organisation's clock (or a site's).
     */
    public static function now(?string $zone = null): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone($zone));
    }

    /**
     * The organisation's calendar date right now, as "Y-m-d" (or a site's).
     */
    public static function today(?string $zone = null): string
    {
        return self::now($zone)->toDateString();
    }

    /**
     * A clock-face time on a calendar date, both as the organisation reads them,
     * returned as the UTC instant they name — "08:00" on 2026-09-14 in Manila is
     * 2026-09-14 00:00Z. A time that daylight saving skips resolves forward.
     */
    public static function at(string $date, string $time, ?string $zone = null): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time, self::zone($zone))->utc();
    }

    /**
     * The organisation's calendar date at an instant, as "Y-m-d".
     */
    public static function localDate(CarbonInterface $instant, ?string $zone = null): string
    {
        return self::local($instant, $zone)->toDateString();
    }

    /**
     * An instant as the organisation's clock shows it — for formatting a stored
     * UTC timestamp for people, never for arithmetic.
     */
    public static function local(CarbonInterface $instant, ?string $zone = null): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::zone($zone));
    }

    /**
     * A date-time somebody typed — a form's `datetime-local` value, "2026-10-02
     * 14:00" — read as the organisation's wall clock and returned as the UTC
     * instant it names. A value that carries its own offset keeps it.
     */
    public static function parse(string $wallClock, ?string $zone = null): CarbonImmutable
    {
        return CarbonImmutable::parse($wallClock, self::zone($zone))->utc();
    }

    /**
     * Every zone an organisation may keep.
     *
     * PHP's canonical list: backward-compatible aliases such as "Asia/Calcutta"
     * are left out, so a stored zone always has one spelling.
     *
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * The zones as a picker offers them: grouped by their offset today, west to
     * east, with the offset spelled out so "Manila" and "Singapore" read as the
     * same clock.
     *
     * @return list<array{value: string, label: string, offset: string}>
     */
    public static function options(): array
    {
        $now = CarbonImmutable::now('UTC');

        $zones = array_map(function (string $identifier) use ($now): array {
            $seconds = (new DateTimeZone($identifier))->getOffset($now);

            return [
                'value' => $identifier,
                'label' => str_replace('_', ' ', $identifier),
                'offset' => self::formatOffset($seconds),
                'seconds' => $seconds,
            ];
        }, self::identifiers());

        usort($zones, fn (array $a, array $b): int => [$a['seconds'], $a['value']] <=> [$b['seconds'], $b['value']]);

        return array_map(
            fn (array $zone): array => ['value' => $zone['value'], 'label' => $zone['label'], 'offset' => $zone['offset']],
            $zones,
        );
    }

    /**
     * "UTC+08:00" from an offset in seconds.
     */
    private static function formatOffset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('UTC%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}

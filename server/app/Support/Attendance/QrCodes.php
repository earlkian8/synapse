<?php

namespace App\Support\Attendance;

use App\Models\AttendanceQrStation;
use App\Models\User;
use App\Support\Hashid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The codes a QR station shows (ADR 0103).
 *
 * A code names its station and a **window** — the stretch of
 * {@see AttendanceQrStation::$rotate_seconds} it belongs to — and is signed
 * with the station's secret:
 *
 *     SQ1.<station hashid>.<window>.<signature>
 *
 * Three things make a code worth nothing away from the screen:
 *
 *  - **It runs out.** A code is good for its own window and the one after (so
 *    a scan started just before the change still lands): at most two
 *    rotations, 30 seconds at the default.
 *  - **Only what a screen was given counts.** The screen asks for the next
 *    {@see BATCH_SECONDS} of codes every {@see REFRESH_SECONDS}, and a window
 *    is accepted only up to the last one a screen was given. With the screen
 *    closed, every code stops within {@see BATCH_SECONDS}, even one signed
 *    correctly.
 *  - **It cannot be made.** The secret never leaves the server; the screen is
 *    sent signed codes, not the means to sign one. Resetting the secret ends
 *    every code at once.
 *
 * **Why a batch, not a code at a time.** A screen asking for each code as it
 * came due was a request every 15 seconds, all day, for every screen — the
 * cost of a station grew with how fast its code changed. Asking for a few
 * minutes of codes at once keeps the code changing as fast as before for a
 * request every two minutes, whatever the rotation; the screen shows each
 * code only in its own window. What the screen holds ahead of time is at most
 * three minutes of codes, each still needing a phone at the site in its own
 * half-minute to be worth anything.
 *
 * What a code does not prove is who is holding the phone or where it is; the
 * scan's own checks do that ({@see QrScans}).
 */
final class QrCodes
{
    public const PREFIX = 'SQ1';

    /** What a phone's camera opens at an app-only station: the app, at its scanner. */
    public const SCHEME = 'synapse://qr/';

    /** The path a station that takes any phone shows its codes under. */
    public const WEB_PATH = '/q/';

    /** How far ahead the screen is given codes. */
    public const BATCH_SECONDS = 180;

    /** How often the screen asks for more — before the last batch runs out. */
    public const REFRESH_SECONDS = 120;

    /** Characters of signature kept: 132 bits, plenty against guessing. */
    private const SIGNATURE_LENGTH = 22;

    /**
     * The codes for the next {@see BATCH_SECONDS}, from the window now: each
     * marked as given to a screen, and the station marked as showing (by whom,
     * and from which address, for a station that wants scans from the screen's
     * own network).
     *
     * @return array{codes: list<array{code: string, payload: string, window: int, starts_at: string, expires_at: string}>, rotate_seconds: int, refresh_in: int, now: string}
     */
    public static function issue(AttendanceQrStation $station, ?User $by = null, ?string $ip = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rotate = self::rotate($station);
        $first = intdiv($now->getTimestamp(), $rotate);
        $last = intdiv($now->getTimestamp() + self::BATCH_SECONDS, $rotate);

        $codes = [];

        for ($window = $first; $window <= $last; $window++) {
            $code = self::code($station, $window);

            $codes[] = [
                'code' => $code,
                'payload' => self::payload($station, $code),
                'window' => $window,
                'starts_at' => CarbonImmutable::createFromTimestamp($window * $rotate)->toIso8601String(),
                'expires_at' => CarbonImmutable::createFromTimestamp(($window + 1) * $rotate)->toIso8601String(),
            ];
        }

        // Two screens on one station: the furthest either was given stands.
        $key = self::shownKey($station);
        $through = max($last, (int) Cache::get($key, 0));
        Cache::put($key, $through, ($through + 2) * $rotate - $now->getTimestamp() + 10);

        // By query: the screen asks every two minutes, and this is no edit.
        AttendanceQrStation::query()->whereKey($station->getKey())->update([
            'last_shown_at' => $now,
            'last_shown_by' => $by?->id,
            'last_shown_ip' => $ip,
        ]);

        return [
            'codes' => $codes,
            'rotate_seconds' => $rotate,
            'refresh_in' => self::REFRESH_SECONDS,
            'now' => $now->toIso8601String(),
        ];
    }

    /**
     * What the screen draws for a code: the app's link at an app-only station;
     * at one that takes any phone, a web address any camera opens — which the
     * app's own scanner reads just the same.
     */
    public static function payload(AttendanceQrStation $station, string $code): string
    {
        return $station->allowsBrowser()
            ? rtrim((string) config('app.url'), '/').self::WEB_PATH.$code
            : self::SCHEME.$code;
    }

    /**
     * Read a scanned code — the bare code, the app link or the web address it
     * was shown as — into the station it names and its window, without judging
     * it. Null when it is not a code of ours at all.
     *
     * @return array{hashid: string, window: int, signature: string}|null
     */
    public static function parse(string $scanned): ?array
    {
        $scanned = trim($scanned);

        if (strlen($scanned) > 300) {
            return null;
        }

        if (str_starts_with($scanned, self::SCHEME)) {
            $scanned = substr($scanned, strlen(self::SCHEME));
        } elseif (preg_match('#^https?://[^/\s]+'.preg_quote(self::WEB_PATH, '#').'([^/?\#\s]+)$#', $scanned, $match) === 1) {
            $scanned = $match[1];
        }

        if (strlen($scanned) > 120) {
            return null;
        }

        $parts = explode('.', $scanned);

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || ! ctype_digit($parts[2]) || $parts[1] === '') {
            return null;
        }

        return ['hashid' => $parts[1], 'window' => (int) $parts[2], 'signature' => $parts[3]];
    }

    /**
     * The station a parsed code names, within the signed-in person's company —
     * a station of another company is nobody's here. Archived stations are not
     * found.
     *
     * @param  array{hashid: string, window: int, signature: string}  $parsed
     */
    public static function stationOf(array $parsed): ?AttendanceQrStation
    {
        $id = Hashid::decode($parsed['hashid']);

        return $id === null ? null : AttendanceQrStation::query()->with('location')->find($id);
    }

    /**
     * Judge a parsed code against its station at a moment.
     *
     * @param  array{hashid: string, window: int, signature: string}  $parsed
     *
     * @throws QrScanException `invalid`, `expired` or `not_showing`
     */
    public static function verify(AttendanceQrStation $station, array $parsed, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $window = $parsed['window'];

        if (! hash_equals(self::signature($station, $window), $parsed['signature'])) {
            throw new QrScanException('invalid', 'That code isn’t one this station showed. Scan the code on the screen.');
        }

        $current = intdiv($now->getTimestamp(), self::rotate($station));

        if ($window > $current || $window < $current - 1) {
            throw new QrScanException('expired', 'That code has already changed. Scan the one on the screen now.');
        }

        if ($window > (int) Cache::get(self::shownKey($station), -1)) {
            throw new QrScanException('not_showing', 'That code isn’t on the station’s screen. Scan the one showing there now.');
        }
    }

    /**
     * The code for a window — what {@see issue()} hands the screen. For tests
     * and the screen; nothing else needs to make one.
     */
    public static function code(AttendanceQrStation $station, int $window): string
    {
        return implode('.', [self::PREFIX, $station->hashid, $window, self::signature($station, $window)]);
    }

    private static function signature(AttendanceQrStation $station, int $window): string
    {
        $mac = hash_hmac('sha256', $station->hashid.'.'.$window.'.'.self::rotate($station), (string) $station->secret, true);

        return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, self::SIGNATURE_LENGTH);
    }

    private static function rotate(AttendanceQrStation $station): int
    {
        $rotate = (int) $station->rotate_seconds;

        return in_array($rotate, AttendanceQrStation::ROTATE_CHOICES, true) ? $rotate : AttendanceQrStation::DEFAULT_ROTATE_SECONDS;
    }

    /**
     * Where the last window a screen was given is kept. The rotation is part of
     * it, so changing it starts afresh rather than comparing windows of two
     * lengths.
     */
    private static function shownKey(AttendanceQrStation $station): string
    {
        return 'attendance-qr:shown-through:'.$station->getKey().':'.self::rotate($station);
    }
}

<?php

namespace App\Support\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceQrScan;
use App\Models\AttendanceQrStation;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\Scopes\OrganizationScope;
use App\Support\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Somebody scanning a QR station's code (ADR 0103), with the app or — at a
 * station that takes any phone — with their phone's own camera and browser.
 *
 * **With the app**, in two steps:
 *
 *  1. **The scan** ({@see scan()}) proves they were at the screen, there and
 *     then: a live code the screen showed ({@see QrCodes}), from a phone whose
 *     location is real, precise and inside the station's site, and that has not
 *     clocked in somebody else lately. It buys a **ticket** good for
 *     {@see TICKET_SECONDS} — time to take a selfie, if their policy wants one.
 *  2. **The punch** ({@see punch()}) spends the ticket through
 *     {@see AttendanceClock::capture()}, held to the day's order and the
 *     person's policy like any punch, as source `qr`.
 *
 * **With a browser**, the phone's camera opens the code's web address. Typing a
 * number and PIN takes longer than a code lives, so opening the page
 * **claims** the code ({@see claim()}): the live code is checked then, and buys
 * {@see CLAIM_SECONDS} for this browser alone to say who is scanning
 * ({@see identify()}: employee number and the PIN they use at kiosks) and where
 * they are. The same checks of place, phone and policy follow, and the same
 * ticket and punch. A browser can't say whether its location is simulated, and
 * its "phone" is a cookie; a station that must be sure keeps to the app, or
 * asks for its own network ({@see sameNetwork()}).
 *
 * Every scan of a real station is written down, accepted or refused, with why
 * ({@see AttendanceQrScan}). A refusal that looks like somebody getting round the
 * station — a code from elsewhere, a faked location, one phone for two people —
 * goes to the activity log as well.
 *
 * What it cannot stop: a colleague who is at the screen holding your phone,
 * signed in as you (or knowing your PIN). The policy's selfie, the station's
 * list of who just scanned, and the one-phone-one-person rule are the checks for
 * that.
 */
final class QrScans
{
    /** How long somebody has between the scan and the punch. */
    public const TICKET_SECONDS = 120;

    /** How long an opened page holds the code it was opened with, in a browser. */
    public const CLAIM_SECONDS = 180;

    /** The roughest position a scan is accepted with, in metres. */
    public const MAX_ACCURACY_METERS = 100;

    /** How long a phone that clocked somebody in belongs to them, in hours. */
    public const DEVICE_HOURS = 12;

    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * Judge a scan from the app, and on success hand back a ticket for the
     * punch.
     *
     * @param  array{latitude?: mixed, longitude?: mixed, accuracy?: mixed, mocked?: mixed}  $position
     * @return array{ticket: string, expires_in: int, station: array{name: string, site: ?string}, day: array<string, mixed>}
     *
     * @throws QrScanException
     */
    public function scan(Employee $employee, string $scanned, array $position, ?string $deviceId, ?string $ip = null): array
    {
        $parsed = QrCodes::parse($scanned);
        $station = $parsed !== null ? QrCodes::stationOf($parsed) : null;

        // Not a code of this company's: there is no station to write it against.
        if ($parsed === null || $station === null) {
            throw new QrScanException('invalid', 'That isn’t a SYNAPSE attendance code for your company. Scan the code on your station’s screen.');
        }

        $device = filled($deviceId) ? self::deviceHash((string) $deviceId) : null;
        $record = $this->record($station, $employee, 'app', $position, $device, $ip);

        try {
            QrCodes::verify($station, $parsed);

            if ($device === null) {
                throw new QrScanException('no_device', 'Update the SYNAPSE app to clock in with a QR code.');
            }

            $distance = $this->judge($station, $employee, $record, $ip);
        } catch (QrScanException $refusal) {
            $this->refuse($station, $employee, $record, $refusal);

            throw $refusal;
        }

        return $this->accept($station, $employee, $record, $distance);
    }

    /**
     * A phone's browser opened a code's address: check the code now, while it
     * is live, and hold it for this browser for {@see CLAIM_SECONDS}. The page
     * is handed the claim, never the code's means.
     *
     * Refused — and, when it looks like somebody getting round the station,
     * written down without a name — when the code isn't live, isn't one the
     * screen was given, or the station only takes the app.
     *
     * @param  array{hashid: string, window: int, signature: string}  $parsed
     * @return array{claim: string, station: AttendanceQrStation, expires_in: int}
     *
     * @throws QrScanException
     */
    public function claim(AttendanceQrStation $station, array $parsed, string $device, ?string $ip = null): array
    {
        $record = $this->record($station, null, 'browser', [], $device, $ip);

        try {
            QrCodes::verify($station, $parsed);
        } catch (QrScanException $refusal) {
            if (in_array($refusal->reason, AttendanceQrScan::SUSPICIOUS, true)) {
                $this->refuse($station, null, $record, $refusal);
            }

            throw $refusal;
        }

        if (! $station->allowsBrowser()) {
            throw new QrScanException('app_only', 'This station takes scans from the SYNAPSE app only. Open the app and tap the scan button.');
        }

        if (! $station->isUsable()) {
            throw new QrScanException($station->is_active ? 'no_site' : 'inactive', $station->is_active
                ? 'This QR station isn’t set to a site yet. Ask HR.'
                : 'This QR station is switched off. Ask HR, or clock in another way.');
        }

        $claim = Str::random(48);

        Cache::put(self::claimKey($claim), [
            'station' => $station->id,
            'organization' => $station->organization_id,
            'device' => $device,
            'window' => $parsed['window'],
        ], self::CLAIM_SECONDS);

        return ['claim' => $claim, 'station' => $station, 'expires_in' => self::CLAIM_SECONDS];
    }

    /**
     * What a claim holds, when it is live and this browser's — the station's
     * company, to bind before anything else is read.
     *
     * @return array{station: int, organization: int, device: string, window: int}|null
     */
    public static function claimed(?string $claim, string $device): ?array
    {
        $held = is_string($claim) && strlen($claim) === 48 ? Cache::get(self::claimKey($claim)) : null;

        return is_array($held) && hash_equals((string) ($held['device'] ?? ''), $device) ? $held : null;
    }

    /**
     * Say who is scanning in a browser — their employee number and PIN — and
     * where they are; on success, the claim is spent on a ticket for the punch,
     * exactly as an app scan's.
     *
     * @param  array{latitude?: mixed, longitude?: mixed, accuracy?: mixed}  $position
     * @return array{ticket: string, expires_in: int, station: array{name: string, site: ?string}, day: array<string, mixed>, first_name: string, must_change_pin: bool}
     *
     * @throws QrScanException
     */
    public function identify(string $claim, string $device, string $number, string $pin, array $position, ?string $ip = null): array
    {
        $held = self::claimed($claim, $device);
        $station = $held !== null ? AttendanceQrStation::query()->with('location')->find($held['station']) : null;

        if ($held === null || $station === null) {
            throw new QrScanException('expired', 'That took too long. Scan the code on the screen again.');
        }

        try {
            $holder = KioskPins::check('qr-sign-in:'.$station->id, 'this station', "the QR station “{$station->name}”", $number, $pin);
        } catch (KioskException $wrong) {
            $refusal = new QrScanException('wrong_pin', $wrong->getMessage());
            $this->refuse($station, null, $this->record($station, null, 'browser', $position, $device, $ip), $refusal);

            throw $refusal;
        }

        $employee = $holder->employee;
        $record = $this->record($station, $employee, 'browser', [...$position, 'mocked' => false], $device, $ip);

        try {
            $distance = $this->judge($station, $employee, $record, $ip);
        } catch (QrScanException $refusal) {
            $this->refuse($station, $employee, $record, $refusal);

            throw $refusal;
        }

        // One opened page, one person.
        Cache::forget(self::claimKey($claim));

        return [
            ...$this->accept($station, $employee, $record, $distance, $holder),
            'first_name' => (string) $employee->first_name,
            'must_change_pin' => (bool) $holder->must_change,
        ];
    }

    /**
     * The person a browser's ticket was bought for, with the PIN that bought
     * it — when the ticket is live and was bought in this browser.
     *
     * @return array{employee: Employee, pin: ?EmployeeKioskPin}|null
     */
    public static function ticketHolder(?string $ticket, string $device): ?array
    {
        $held = is_string($ticket) && strlen($ticket) === 48 ? Cache::get(self::ticketKey($ticket)) : null;

        if (! is_array($held) || ! hash_equals((string) ($held['device'] ?? ''), $device)) {
            return null;
        }

        // Read across companies: the browser may be signed in to another one,
        // whose tenant must not hide this person. The caller binds theirs next.
        $employee = Employee::query()->withoutGlobalScope(OrganizationScope::class)->find($held['employee'] ?? null);
        $pin = isset($held['pin']) ? EmployeeKioskPin::query()->withoutGlobalScope(OrganizationScope::class)->find($held['pin']) : null;

        return $employee === null ? null : ['employee' => $employee, 'pin' => $pin];
    }

    /**
     * Spend a ticket on a punch.
     *
     * @throws QrScanException when the ticket is not theirs or has run out
     * @throws AttendancePunchException when the punch itself is refused
     */
    public function punch(Employee $employee, string $ticket, string $type, ?string $photo = null): CapturedPunch
    {
        $held = strlen($ticket) === 48 ? Cache::get(self::ticketKey($ticket)) : null;

        if (! is_array($held) || ($held['employee'] ?? null) !== $employee->id) {
            throw new QrScanException('expired', 'Your scan has run out. Scan the code on the screen again.');
        }

        $station = AttendanceQrStation::query()->with('location')->find($held['station'] ?? null);

        if ($station === null || ! $station->isUsable()) {
            Cache::forget(self::ticketKey($ticket));

            throw new QrScanException('inactive', 'This QR station was switched off. Ask HR, or clock in another way.');
        }

        if (isset($held['pin']) && EmployeeKioskPin::query()->whereKey($held['pin'])->where('must_change', true)->exists()) {
            throw new QrScanException('must_change_pin', 'Choose your own PIN before you punch.');
        }

        $captured = $this->clock->capture($employee, $type, [
            'source' => 'qr',
            'qr_station' => $station,
            'device_hash' => $held['device'] ?? null,
            'latitude' => $held['latitude'] ?? null,
            'longitude' => $held['longitude'] ?? null,
            'accuracy' => $held['accuracy'] ?? null,
            'photo' => $photo,
        ]);

        Cache::forget(self::ticketKey($ticket));

        AttendanceQrScan::query()->whereKey($held['scan'] ?? null)->update([
            'attendance_punch_id' => $captured->punch->id,
            'punch_type' => $type,
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: 'QR punch ('.$type.') at “'.$station->name.'”'.(($held['client'] ?? 'app') === 'browser' ? ', in a browser' : ''),
            subject: $captured->record,
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        return $captured;
    }

    /**
     * What the person's day allows now, as the scanner needs it after a scan.
     *
     * @return array{next_expected: ?string, allowed: list<string>, selfie_required: bool, qr_allowed: bool}
     */
    public function day(Employee $employee): array
    {
        $record = $this->clock->currentRecord($employee);
        $policy = $this->clock->policyOf($record);

        return [
            'next_expected' => $this->clock->nextExpected($record),
            'allowed' => $this->clock->allowed($record),
            'selfie_required' => $policy->selfieRequired,
            'qr_allowed' => in_array('qr', $policy->allowedSources, true),
        ];
    }

    /**
     * A phone's install id (or a browser's cookie) as it is kept: hashed, so the
     * table says which punches came from one phone without holding anything the
     * phone sent.
     */
    public static function deviceHash(string $deviceId): string
    {
        return hash('sha256', 'synapse-device|'.$deviceId);
    }

    /**
     * Whether a phone reached us through the same internet connection as the
     * station's screen: the same public address, or — on IPv6, where every
     * device has its own — the same /64 network. Behind a proxy this is only as
     * good as the proxy's trusted headers.
     */
    public static function sameNetwork(?string $ip, ?string $screen): bool
    {
        if ($ip === null || $screen === null || $ip === '' || $screen === '') {
            return false;
        }

        if ($ip === $screen) {
            return true;
        }

        $a = @inet_pton($ip);
        $b = @inet_pton($screen);

        return $a !== false && $b !== false
            && strlen($a) === 16 && strlen($b) === 16
            && substr($a, 0, 8) === substr($b, 0, 8);
    }

    /**
     * The checks of place, phone and policy every scan goes through once its
     * code has been judged. Returns the distance to the station's site (0
     * inside a shape).
     *
     * @param  array<string, mixed>  $record
     *
     * @throws QrScanException
     */
    private function judge(AttendanceQrStation $station, Employee $employee, array $record, ?string $ip): int
    {
        if (! $station->is_active) {
            throw new QrScanException('inactive', 'This QR station is switched off. Ask HR, or clock in another way.');
        }

        if (! $station->isUsable()) {
            throw new QrScanException('no_site', 'This QR station isn’t set to a site, so nobody can clock in at it yet. Ask HR to set where it stands.');
        }

        if ($record['client'] === 'browser' && ! $station->allowsBrowser()) {
            throw new QrScanException('app_only', 'This station takes scans from the SYNAPSE app only. Open the app and tap the scan button.');
        }

        if ($record['mocked']) {
            throw new QrScanException('mocked', 'Your phone says its location is simulated. Turn off any app that changes your location, then scan again.');
        }

        $latitude = $record['latitude'];
        $longitude = $record['longitude'];
        $accuracy = $record['accuracy'];

        if ($latitude === null || $longitude === null) {
            throw new QrScanException('no_location', $record['client'] === 'browser'
                ? 'Allow this page to use your location, then try again. A QR punch needs to know you’re at the station.'
                : 'Turn on location for SYNAPSE, then scan again. A QR punch needs to know you’re at the station.');
        }

        if ($accuracy === null || $accuracy > self::MAX_ACCURACY_METERS) {
            throw new QrScanException('imprecise', 'Your phone can’t tell closely enough where you are'.($accuracy !== null ? ' (within '.(int) round($accuracy).' m)' : '').'. Turn on precise location, step near a window, and scan again.');
        }

        $verdict = GeofenceCheck::check($latitude, $longitude, $accuracy, collect([$station->location]));

        if ($verdict === null || ! $verdict->inside) {
            $distance = $verdict?->distanceMeters;

            throw new QrScanException(
                'away',
                'You’re '.($distance !== null ? self::distance($distance).' from ' : 'away from ').$station->location->name.', where this station is. Scan it when you’re there.',
                $distance,
            );
        }

        if ($station->same_network && ! self::sameNetwork($ip, $station->last_shown_ip)) {
            throw new QrScanException('other_network', 'Connect to the office Wi-Fi, then scan again. This station only takes scans on the same network as its screen.');
        }

        $shared = AttendancePunch::query()
            ->where('device_hash', $record['device_hash'])
            ->where('employee_id', '!=', $employee->id)
            ->where('punched_at', '>=', CarbonImmutable::now()->subHours(self::DEVICE_HOURS))
            ->exists();

        if ($shared) {
            throw new QrScanException('shared_device', 'This phone clocked in somebody else today. Everyone scans with their own phone.');
        }

        if (! $this->day($employee)['qr_allowed']) {
            throw new QrScanException('punch_refused', 'Your attendance policy doesn’t take punches from a QR station. Clock in the way your company asks, or ask HR.');
        }

        return $verdict->distanceMeters;
    }

    /**
     * Write an accepted scan down and buy its ticket.
     *
     * @param  array<string, mixed>  $record
     * @return array{ticket: string, expires_in: int, station: array{name: string, site: ?string}, day: array<string, mixed>}
     */
    private function accept(AttendanceQrStation $station, Employee $employee, array $record, int $distance, ?EmployeeKioskPin $pin = null): array
    {
        $scan = AttendanceQrScan::create([...$record, 'outcome' => 'accepted', 'distance_meters' => $distance]);

        $ticket = Str::random(48);

        Cache::put(self::ticketKey($ticket), [
            'employee' => $employee->id,
            'station' => $station->id,
            'scan' => $scan->id,
            'client' => $record['client'],
            'device' => $record['device_hash'],
            'pin' => $pin?->id,
            'latitude' => $record['latitude'],
            'longitude' => $record['longitude'],
            'accuracy' => $record['accuracy'],
        ], self::TICKET_SECONDS);

        return [
            'ticket' => $ticket,
            'expires_in' => self::TICKET_SECONDS,
            'station' => [
                'name' => $station->name,
                'site' => $station->location?->name,
            ],
            'day' => $this->day($employee),
        ];
    }

    /**
     * The row a scan is written down as, before its outcome.
     *
     * @param  array{latitude?: mixed, longitude?: mixed, accuracy?: mixed, mocked?: mixed}  $position
     * @return array<string, mixed>
     */
    private function record(AttendanceQrStation $station, ?Employee $employee, string $client, array $position, ?string $device, ?string $ip): array
    {
        return [
            'attendance_qr_station_id' => $station->id,
            'employee_id' => $employee?->id,
            'client' => $client,
            'latitude' => is_numeric($position['latitude'] ?? null) ? (float) $position['latitude'] : null,
            'longitude' => is_numeric($position['longitude'] ?? null) ? (float) $position['longitude'] : null,
            'accuracy' => is_numeric($position['accuracy'] ?? null) ? (float) $position['accuracy'] : null,
            'mocked' => filter_var($position['mocked'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'device_hash' => $device,
            'ip' => $ip,
        ];
    }

    /**
     * Write a refused scan down — and to the activity log when it looks like
     * somebody getting round the station.
     *
     * @param  array<string, mixed>  $record
     */
    private function refuse(AttendanceQrStation $station, ?Employee $employee, array $record, QrScanException $refusal): void
    {
        $scan = AttendanceQrScan::create([
            ...$record,
            'outcome' => 'refused',
            'reason' => $refusal->reason,
            'distance_meters' => $refusal->distanceMeters,
        ]);

        if (! $scan->isSuspicious()) {
            return;
        }

        ActivityLogger::log(
            event: 'updated',
            description: 'Refused a QR scan at “'.$station->name.'”: '.(AttendanceQrScan::REASONS[$refusal->reason] ?? $refusal->reason),
            subject: $station,
            logName: 'attendance',
            properties: ['reason' => $refusal->reason, 'distance_meters' => $refusal->distanceMeters, 'client' => $record['client']],
            subjectLabel: $employee?->full_name ?? 'Someone not yet identified',
        );
    }

    /** "80 m", "1.2 km". */
    private static function distance(int $meters): string
    {
        return $meters < 1000 ? "{$meters} m" : number_format($meters / 1000, 1).' km';
    }

    private static function ticketKey(string $ticket): string
    {
        return 'attendance-qr-ticket:'.hash('sha256', $ticket);
    }

    private static function claimKey(string $claim): string
    {
        return 'attendance-qr-claim:'.hash('sha256', $claim);
    }
}

<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\QrWebIdentifyRequest;
use App\Http\Requests\Attendance\QrWebPinRequest;
use App\Http\Requests\Attendance\QrWebPunchRequest;
use App\Models\AttendanceQrStation;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePunchException;
use App\Support\Attendance\KioskException;
use App\Support\Attendance\KioskPins;
use App\Support\Attendance\QrCodes;
use App\Support\Attendance\QrScanException;
use App\Support\Attendance\QrScans;
use App\Support\Files\Uploads;
use App\Support\Hashid;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scanning a QR station with any phone's camera, no app (ADR 0103): the code's
 * web address opens here, in the phone's browser, at a station HR set to take
 * any phone.
 *
 * Nobody signs in. Opening the page claims the live code for this browser
 * ({@see QrScans::claim()}); the person then gives their employee number, the
 * PIN they use at kiosks, and their location ({@see QrScans::identify()}),
 * chooses their own PIN if HR's is still the one they have, and punches. The
 * browser is known by a random id in a cookie nothing on the page can read,
 * standing in for the app's install id in the one-phone-one-person rule.
 *
 * The station's company is bound as the tenant from the station (or the claim,
 * or the ticket) before anything else is read: a signed-in session in the same
 * browser plays no part.
 */
class QrWebScanController extends Controller
{
    /** The cookie a browser that has scanned keeps its id in. */
    public const DEVICE_COOKIE = 'synapse_qr_device';

    public function __construct(
        private readonly QrScans $scans,
        private readonly AttendanceClock $clock,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * The page a code's address opens: ready to say who is scanning, or why it
     * can't be scanned here.
     */
    public function show(Request $request, string $code): Response
    {
        $device = $request->cookie(self::DEVICE_COOKIE);
        $device = is_string($device) && strlen($device) === 40 ? $device : Str::random(40);

        Cookie::queue(Cookie::make(
            self::DEVICE_COOKIE,
            $device,
            minutes: 60 * 24 * 365 * 2,
            path: '/',
            secure: (bool) (config('session.secure') ?? $request->secure()),
            httpOnly: true,
            sameSite: 'lax',
        ));

        $parsed = QrCodes::parse($code);
        $id = $parsed !== null ? Hashid::decode($parsed['hashid']) : null;
        $station = $id !== null
            ? AttendanceQrStation::query()->withoutGlobalScope(OrganizationScope::class)->with('organization')->find($id)
            : null;

        if ($parsed === null || $station === null || $station->organization === null) {
            return $this->page($request, 'invalid', 'That isn’t a SYNAPSE attendance code. Scan the code on your station’s screen.');
        }

        $this->tenancy->set($station->organization);
        $station->load('location');

        try {
            $claimed = $this->scans->claim($station, $parsed, QrScans::deviceHash($device), $request->ip());
        } catch (QrScanException $refusal) {
            return $this->page($request, $refusal->reason === 'app_only' ? 'app_only' : 'refused', $refusal->getMessage(), $station, [
                'app_link' => QrCodes::SCHEME.$code,
            ]);
        }

        return $this->page($request, 'ready', null, $station, [
            'claim' => $claimed['claim'],
            'expires_in' => $claimed['expires_in'],
            // Somebody with the app can scan there instead.
            'app_link' => QrCodes::SCHEME.$code,
        ]);
    }

    /**
     * Who is scanning, and where: on a match, a ticket for the punch, their day,
     * and whether they must choose their own PIN first.
     */
    public function identify(QrWebIdentifyRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $held = $device !== null ? QrScans::claimed($request->input('claim'), $device) : null;

        if ($device === null || $held === null) {
            return $this->refusal('expired', 'That took too long. Scan the code on the screen again.');
        }

        $this->bind($held['organization']);

        try {
            $result = $this->scans->identify(
                $request->string('claim')->toString(),
                $device,
                $request->string('number')->toString(),
                $request->string('pin')->toString(),
                $request->only(['latitude', 'longitude', 'accuracy']),
                $request->ip(),
            );
        } catch (QrScanException $e) {
            return $this->refusal($e->reason, $e->getMessage());
        }

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Choose one's own PIN in place of the one HR issued — once, before the
     * first punch, as at a kiosk.
     */
    public function pin(QrWebPinRequest $request): JsonResponse
    {
        $holder = $this->holder($request);
        $pin = $holder['pin'] ?? null;

        if ($holder === null || ! $pin instanceof EmployeeKioskPin) {
            return $this->refusal('expired', 'That took too long. Scan the code on the screen again.');
        }

        try {
            KioskPins::change($pin, $request->string('pin')->toString());
        } catch (KioskException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['pin' => [$e->getMessage()]]], 422);
        }

        ActivityLogger::log(
            event: 'updated',
            description: 'Chose their own kiosk PIN, at a QR station',
            subject: $holder['employee'],
            logName: 'attendance',
            subjectLabel: $holder['employee']->full_name,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Punch, with the ticket the number and PIN bought. A refused punch keeps
     * no photo, and the ticket stays good so another punch can be chosen.
     */
    public function punch(QrWebPunchRequest $request): JsonResponse
    {
        $holder = $this->holder($request);

        if ($holder === null) {
            return $this->refusal('expired', 'That took too long. Scan the code on the screen again.');
        }

        $employee = $holder['employee'];
        $type = $request->string('type')->toString();
        $photo = $request->hasFile('photo')
            ? $request->file('photo')->store('attendance/punches', Uploads::DISK)
            : null;

        try {
            $captured = $this->scans->punch($employee, $request->string('ticket')->toString(), $type, $photo);
        } catch (QrScanException|AttendancePunchException $e) {
            if ($photo !== null) {
                Uploads::disk()->delete($photo);
            }

            return $this->refusal($e instanceof QrScanException ? $e->reason : 'punch_refused', $e->getMessage());
        }

        $record = $captured->record->load('punches');

        return response()->json([
            'type' => $type,
            'at' => $captured->punch->punched_at->toIso8601String(),
            'first_name' => $employee->first_name,
            'next_expected' => $this->clock->nextExpected($record),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * The person a ticket was bought for in this browser, with their company
     * bound — or null.
     *
     * @return array{employee: Employee, pin: ?EmployeeKioskPin}|null
     */
    private function holder(Request $request): ?array
    {
        $device = $this->device($request);
        $holder = $device !== null ? QrScans::ticketHolder($request->input('ticket'), $device) : null;

        if ($holder === null) {
            return null;
        }

        $this->bind($holder['employee']->organization_id);

        return $holder;
    }

    /** This browser's id, hashed as a phone's install id is. */
    private function device(Request $request): ?string
    {
        $device = $request->cookie(self::DEVICE_COOKIE);

        return is_string($device) && strlen($device) === 40 ? QrScans::deviceHash($device) : null;
    }

    private function bind(int $organizationId): void
    {
        $organization = Organization::query()->find($organizationId);

        abort_if($organization === null, 404);

        $this->tenancy->set($organization);
    }

    private function refusal(string $reason, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'reason' => $reason], 422)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  'ready'|'invalid'|'refused'|'app_only'  $state
     * @param  array<string, mixed>  $extra
     */
    private function page(Request $request, string $state, ?string $message, ?AttendanceQrStation $station = null, array $extra = []): Response
    {
        $organization = $station?->organization;

        return Inertia::render('qr-scan', [
            'state' => $state,
            'message' => $message,
            'station' => $station === null ? null : [
                'name' => $station->name,
                'site' => $station->location?->name,
                'timezone' => OrganizationClock::zone($station->location?->timezone),
            ],
            'organization' => $organization === null ? null : [
                'name' => $organization->name,
                'logo_url' => $organization->logo_url,
            ],
            'pin_length' => EmployeeKioskPin::LENGTH,
            'max_accuracy' => QrScans::MAX_ACCURACY_METERS,
            ...$extra,
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}

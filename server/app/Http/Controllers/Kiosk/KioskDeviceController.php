<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateKiosk;
use App\Http\Requests\Kiosk\KioskPairRequest;
use App\Http\Requests\Kiosk\KioskPinRequest;
use App\Http\Requests\Kiosk\KioskPunchRequest;
use App\Http\Requests\Kiosk\KioskSignInRequest;
use App\Http\Requests\Kiosk\KioskTicketRequest;
use App\Models\AttendanceKiosk;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePunchException;
use App\Support\Attendance\KioskException;
use App\Support\Attendance\KioskPins;
use App\Support\Attendance\KioskPresenter;
use App\Support\Attendance\KioskTickets;
use App\Support\Files\Uploads;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kiosk itself (ADR 0092): a shared device at a site, where people who have
 * no phone clock in with their employee number and a PIN.
 *
 * Nobody signs in here. The device is known by the secret pairing left in its
 * cookie ({@see AuthenticateKiosk}); a person is known by their number and PIN
 * ({@see KioskPins}), which buy a two-minute ticket ({@see KioskTickets}) for
 * the punch. The punch itself goes through {@see AttendanceClock::capture()},
 * like every other, so a kiosk punch is held to the day's order and the
 * person's policy exactly as a phone's is.
 *
 * Every answer but the page is JSON, read by the kiosk's own script.
 */
class KioskDeviceController extends Controller
{
    public function __construct(
        private readonly AttendanceClock $clock,
        private readonly KioskPresenter $presenter,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * The kiosk page: the pairing screen for a device that is not a kiosk yet,
     * the clock for one that is — and, in a browser somebody is signed in on, a
     * screen asking them to sign out first.
     */
    public function show(Request $request): Response
    {
        if ($request->user() !== null) {
            return $this->page('signed_in', null);
        }

        $kiosk = AuthenticateKiosk::kioskFor($request);

        if ($kiosk === null) {
            return $this->page('unpaired', null);
        }

        return $this->page('ready', $this->tenancy->runFor($kiosk->organization, fn (): array => $this->presenter->kiosk($kiosk)));
    }

    /**
     * Make this device a kiosk, with the code HR was given. The code works once;
     * the device keeps a secret of its own from then on.
     */
    public function pair(KioskPairRequest $request): JsonResponse
    {
        if ($request->user() !== null) {
            return response()->json(['message' => 'Sign out of Synapse in this browser first. A kiosk is used by everyone, so nobody can be signed in on it.', 'reason' => 'signed_in'], 409);
        }

        $kiosk = AttendanceKiosk::findByPairingCode($request->string('code')->toString());

        if ($kiosk === null) {
            return response()->json(['message' => 'That code doesn’t work. A code works once, for '.AttendanceKiosk::PAIRING_MINUTES.' minutes — ask HR for a new one.'], 422);
        }

        $kiosk->load(['organization', 'location']);
        $this->tenancy->set($kiosk->organization);

        $token = $kiosk->pair(KioskPresenter::deviceLabel($request->userAgent()));

        ActivityLogger::log(
            event: 'updated',
            description: "Paired a device as the kiosk “{$kiosk->name}”",
            subject: $kiosk,
            properties: ['device' => $kiosk->device_label],
            logName: 'attendance',
            subjectLabel: $kiosk->name,
        );

        return response()
            ->json(['kiosk' => $this->presenter->kiosk($kiosk)])
            ->withCookie(AuthenticateKiosk::cookie($token));
    }

    /**
     * The kiosk's header and the server's time. Asked every minute, which also
     * keeps the device's session — and so its form token — alive, and tells a
     * kiosk HR has signed out that it no longer is one.
     */
    public function state(Request $request): JsonResponse
    {
        return response()->json(['kiosk' => $this->presenter->kiosk($this->kiosk($request))]);
    }

    /**
     * Somebody's number and PIN. On a match they are named, with their day; on
     * anything else they are told it does not match, and nothing more.
     */
    public function signIn(KioskSignInRequest $request): JsonResponse
    {
        $kiosk = $this->kiosk($request);

        try {
            $pin = KioskPins::signIn($kiosk, $request->string('number')->toString(), $request->string('pin')->toString());
        } catch (KioskException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presenter->person($pin, KioskTickets::issue($kiosk, $pin)));
    }

    /**
     * Choose one's own PIN in place of the one HR issued — the first thing
     * somebody does at a kiosk, so the PIN that clocks them in is theirs alone.
     */
    public function changePin(KioskPinRequest $request): JsonResponse
    {
        $ticket = $request->string('ticket')->toString();
        $pin = KioskTickets::find($this->kiosk($request), $ticket);

        if ($pin === null) {
            return $this->expired();
        }

        try {
            KioskPins::change($pin, $request->string('pin')->toString());
        } catch (KioskException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['pin' => [$e->getMessage()]]], 422);
        }

        ActivityLogger::log(
            event: 'updated',
            description: 'Chose their own kiosk PIN',
            subject: $pin->employee,
            properties: ['kiosk' => $this->kiosk($request)->name],
            logName: 'attendance',
            subjectLabel: $pin->employee->full_name,
        );

        return response()->json($this->presenter->person($pin->refresh()->load('employee'), $ticket));
    }

    /**
     * Punch, with the ticket a number and PIN bought. A refused punch writes
     * nothing — not even the photo taken for it — and the ticket stays good, so
     * the person can choose another; a recorded one spends it.
     */
    public function punch(KioskPunchRequest $request): JsonResponse
    {
        $kiosk = $this->kiosk($request);
        $ticket = $request->string('ticket')->toString();
        $pin = KioskTickets::find($kiosk, $ticket);

        if ($pin === null) {
            return $this->expired();
        }

        if ($pin->must_change) {
            return response()->json(['message' => 'Choose your own PIN before you punch.', 'reason' => 'must_change_pin'], 422);
        }

        $type = $request->string('type')->toString();
        $employee = $pin->employee;
        $photo = $request->hasFile('photo')
            ? $request->file('photo')->store('attendance/punches', Uploads::DISK)
            : null;

        try {
            $captured = $this->clock->capture($employee, $type, [
                'source' => 'kiosk',
                'kiosk' => $kiosk,
                'photo' => $photo,
                'ip' => $request->ip(),
            ]);
        } catch (AttendancePunchException $e) {
            if ($photo !== null) {
                Uploads::disk()->delete($photo);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        KioskTickets::forget($ticket);

        ActivityLogger::log(
            event: 'updated',
            description: $this->punchLabel($type)." at the kiosk “{$kiosk->name}”",
            subject: $captured->record,
            properties: ['kiosk' => $kiosk->name],
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        return response()->json([
            'message' => $this->punchLabel($type),
            'type' => $type,
            'at' => $captured->punch->punched_at->toIso8601String(),
            'first_name' => $employee->first_name,
            'day' => $this->presenter->day($pin),
        ]);
    }

    /**
     * Somebody walked away: their ticket is dropped rather than left to lapse.
     */
    public function done(KioskTicketRequest $request): HttpResponse
    {
        KioskTickets::forget($request->input('ticket'));

        return response()->noContent();
    }

    private function kiosk(Request $request): AttendanceKiosk
    {
        return $request->attributes->get(AuthenticateKiosk::ATTRIBUTE);
    }

    private function expired(): JsonResponse
    {
        return response()->json([
            'message' => 'That took too long. Type your number and PIN again.',
            'reason' => 'expired',
        ], 422);
    }

    /**
     * @param  'signed_in'|'unpaired'|'ready'  $state
     * @param  array<string, mixed>|null  $kiosk
     */
    private function page(string $state, ?array $kiosk): Response
    {
        return Inertia::render('kiosk', [
            'state' => $state,
            'kiosk' => $kiosk,
            'codeLength' => AttendanceKiosk::CODE_LENGTH,
        ]);
    }

    private function punchLabel(string $type): string
    {
        return match ($type) {
            'clock_in' => 'Clocked in',
            'clock_out' => 'Clocked out',
            'break_start' => 'Started break',
            'break_end' => 'Ended break',
            default => 'Punched',
        };
    }
}

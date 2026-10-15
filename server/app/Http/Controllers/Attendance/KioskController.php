<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\KioskRequest;
use App\Models\AttendanceKiosk;
use App\Queries\KiosksScreen;
use App\Support\Attendance\KioskWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance → Kiosks (ADR 0092): the shared devices people without a phone
 * clock in at, and — on the same screen, beside them — who may
 * ({@see KioskPinController}). Kiosks are addressed by hashid; every change goes
 * through {@see KioskWorkflow}, as the assistant's do.
 *
 * A pairing code is a secret shown once, so it is answered as JSON for the
 * screen to show, never put in the page's props or a flash message, which the
 * browser would keep in its history.
 */
class KioskController extends Controller
{
    public function __construct(private readonly KioskWorkflow $workflow) {}

    public function index(Request $request, KiosksScreen $screen): Response
    {
        return Inertia::render('attendance/kiosks', $screen->toArray($request));
    }

    public function store(KioskRequest $request): RedirectResponse
    {
        $kiosk = $this->workflow->create($request->validated(), $request->user());

        return $this->respond("Kiosk “{$kiosk->name}” added. Pair a device to start clocking in on it.");
    }

    public function update(KioskRequest $request, AttendanceKiosk $attendanceKiosk): RedirectResponse
    {
        $this->workflow->update($attendanceKiosk, $request->validated());

        return $this->respond('Kiosk saved.');
    }

    /**
     * A pairing code for the kiosk — for a first device, or one replacing it.
     * The device that types it becomes the kiosk, and any device that was the
     * kiosk before stops being one.
     */
    public function pairingCode(AttendanceKiosk $attendanceKiosk): JsonResponse
    {
        $code = $this->workflow->pairingCode($attendanceKiosk);

        return response()->json([
            'code' => $code,
            'expires_at' => $attendanceKiosk->pairing_expires_at?->toIso8601String(),
            'minutes' => AttendanceKiosk::PAIRING_MINUTES,
            'url' => route('kiosk.show'),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Sign the device out: it stops being the kiosk at once, and shows the
     * pairing screen. The kiosk stays, ready for another device.
     */
    public function unpair(AttendanceKiosk $attendanceKiosk): RedirectResponse
    {
        $this->workflow->unpair($attendanceKiosk);

        return $this->respond('Device signed out. It no longer clocks anybody in.');
    }

    /**
     * Remove the kiosk: its device is signed out and it is gone from the
     * screen. It is kept archived, so punches made at it still name it.
     */
    public function destroy(AttendanceKiosk $attendanceKiosk): RedirectResponse
    {
        $this->workflow->remove($attendanceKiosk);

        return $this->respond("Kiosk “{$attendanceKiosk->name}” removed.");
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

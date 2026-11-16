<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\QrStationRequest;
use App\Models\AttendanceQrScan;
use App\Models\AttendanceQrStation;
use App\Queries\QrStationsScreen;
use App\Support\Attendance\QrCodes;
use App\Support\Attendance\QrImage;
use App\Support\Attendance\QrStationWorkflow;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance → QR stations (ADR 0103): the screens at a site whose changing
 * code people scan with their own phone to clock in.
 *
 * Setting them up needs `attendance.qr.manage`. Showing one needs only
 * `attendance.qr.display`, so a company can sign a front-desk screen in with
 * an account that can do nothing else. The screen asks for the next few
 * minutes of codes every two minutes ({@see code()}), as JSON, `no-store`,
 * never as page props the browser would keep in its history.
 */
class QrStationController extends Controller
{
    /** How many of the latest accepted scans the screen lists. */
    private const FEED = 6;

    public function __construct(private readonly QrStationWorkflow $workflow) {}

    public function index(Request $request, QrStationsScreen $screen): Response
    {
        abort_unless($request->user()->canAny(['attendance.qr.manage', 'attendance.qr.display']), 403);

        return Inertia::render('attendance/qr', $screen->toArray($request));
    }

    public function store(QrStationRequest $request): RedirectResponse
    {
        $station = $this->workflow->create($request->validated(), $request->user());

        return $this->respond("QR station “{$station->name}” added. Show it on a screen at the site to start.");
    }

    public function update(QrStationRequest $request, AttendanceQrStation $attendanceQrStation): RedirectResponse
    {
        $this->workflow->update($attendanceQrStation, $request->validated());

        return $this->respond('QR station saved.');
    }

    /**
     * Switch the station off or on without changing anything else.
     */
    public function toggle(AttendanceQrStation $attendanceQrStation): RedirectResponse
    {
        $this->workflow->update($attendanceQrStation, ['is_active' => ! $attendanceQrStation->is_active]);

        return $this->respond($attendanceQrStation->is_active
            ? 'QR station switched on.'
            : 'QR station switched off. Its code no longer clocks anybody in.');
    }

    public function reset(AttendanceQrStation $attendanceQrStation): RedirectResponse
    {
        $this->workflow->resetCodes($attendanceQrStation);

        return $this->respond('Codes reset. Every code shown before has stopped working.');
    }

    public function destroy(AttendanceQrStation $attendanceQrStation): RedirectResponse
    {
        $this->workflow->remove($attendanceQrStation);

        return $this->respond("QR station “{$attendanceQrStation->name}” removed.");
    }

    /**
     * The station's screen: full screen, no app around it. It holds no code of
     * its own; it asks for them a few minutes at a time ({@see code()}).
     */
    public function display(AttendanceQrStation $attendanceQrStation, Tenancy $tenancy): Response
    {
        $attendanceQrStation->loadMissing('location');
        $organization = $tenancy->organization();

        return Inertia::render('attendance/qr-display', [
            'station' => [
                'hashid' => $attendanceQrStation->hashid,
                'name' => $attendanceQrStation->name,
                'site' => $attendanceQrStation->location?->name,
                'rotate_seconds' => $attendanceQrStation->rotate_seconds,
                'scan_mode' => $attendanceQrStation->scan_mode,
                'code_url' => route('attendance.qr.code', $attendanceQrStation),
                'timezone' => OrganizationClock::zone($attendanceQrStation->location?->timezone),
            ],
            'organization' => [
                'name' => $organization?->name,
                'logo_url' => $organization?->logo_url,
            ],
            'back_url' => $this->backUrl(),
        ]);
    }

    /**
     * The codes for the next few minutes, each as the grid the screen draws,
     * with when it shows, and the latest people the station clocked in. A
     * switched-off station, or one with no site, is told so and shows nothing
     * to scan. The screen asks again in `refresh_in` seconds — about thirty
     * asks an hour, however fast the code changes.
     */
    public function code(Request $request, AttendanceQrStation $attendanceQrStation): JsonResponse
    {
        $station = $attendanceQrStation->loadMissing('location');

        $state = match (true) {
            ! $station->is_active => 'inactive',
            ! $station->isUsable() => 'no_site',
            default => 'live',
        };

        $issued = $state === 'live' ? QrCodes::issue($station, $request->user(), $request->ip()) : null;

        return response()->json([
            'state' => $state,
            'codes' => $issued === null ? [] : array_map(fn (array $code): array => [
                'window' => $code['window'],
                'starts_at' => $code['starts_at'],
                'expires_at' => $code['expires_at'],
                'matrix' => QrImage::matrix($code['payload']),
            ], $issued['codes']),
            'rotate_seconds' => $issued['rotate_seconds'] ?? $station->rotate_seconds,
            'refresh_in' => $issued['refresh_in'] ?? 60,
            'now' => now()->toIso8601String(),
            'feed' => $this->feed($station),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Who the station clocked in last, for the line at the screen to see their
     * punch land: a first name and an initial, what was punched, when. Nothing
     * else about anybody, and no refusals — those are for HR's screen.
     *
     * @return list<array{id: int, name: string, type: ?string, at: ?string}>
     */
    private function feed(AttendanceQrStation $station): array
    {
        return AttendanceQrScan::query()
            ->with('employee:id,first_name,last_name')
            ->where('attendance_qr_station_id', $station->id)
            ->where('outcome', 'accepted')
            ->whereNotNull('attendance_punch_id')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest('created_at')
            ->latest('id')
            ->limit(self::FEED)
            ->get()
            ->map(fn (AttendanceQrScan $scan): array => [
                'id' => $scan->id,
                'name' => trim(($scan->employee?->first_name ?? 'Someone').' '.mb_substr((string) $scan->employee?->last_name, 0, 1).($scan->employee?->last_name ? '.' : '')),
                'type' => $scan->punch_type,
                'at' => $scan->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Where leaving the screen goes: the list of stations, which whoever may
     * show one can open.
     */
    private function backUrl(): string
    {
        return route('attendance.qr.index');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

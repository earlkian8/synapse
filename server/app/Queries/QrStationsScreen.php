<?php

namespace App\Queries;

use App\Models\AttendancePolicy;
use App\Models\AttendanceQrScan;
use App\Models\AttendanceQrStation;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\WorkLocation;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Attendance → QR stations (ADR 0103): the company's stations with how each is
 * doing today, the latest scans of them — refusals and why first among what HR
 * reads — and what the screen's dialog chooses from. Never a secret: codes are
 * asked for by the station's own screen, a few minutes at a time.
 */
class QrStationsScreen
{
    /** How many of the latest scans the screen lists. */
    private const RECENT = 60;

    /**
     * The page's props, shaped as the client's `QrStationsPageProps`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Somebody who may only put a code on a screen sees the stations to
        // show, never who scanned them.
        $manages = $request->user()->can('attendance.qr.manage');

        $stations = AttendanceQrStation::query()
            ->with(['location:id,name,is_active,deleted_at', 'shownBy:id,first_name,middle_name,last_name,suffix'])
            ->orderBy('name')
            ->get();

        $today = $this->scansToday($stations->modelKeys());

        $recent = ! $manages ? collect() : AttendanceQrScan::query()
            ->with([
                'station:id,name',
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo',
            ])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT)
            ->get();

        return [
            'stations' => $stations->map(fn (AttendanceQrStation $station): array => [
                'hashid' => $station->hashid,
                'name' => $station->name,
                'location' => $station->location !== null
                    ? [
                        'id' => $station->location->id,
                        'name' => $station->location->name,
                        'archived' => $station->location->trashed() || ! $station->location->is_active,
                    ]
                    : null,
                'rotate_seconds' => $station->rotate_seconds,
                'scan_mode' => $station->scan_mode,
                'same_network' => $station->same_network,
                'is_active' => $station->is_active,
                'usable' => $station->isUsable(),
                'showing' => $station->isShowing(),
                'last_shown_at' => $station->last_shown_at?->toIso8601String(),
                'last_shown_by' => $station->shownBy?->full_name,
                'accepted_today' => $today[$station->id]['accepted'] ?? 0,
                'refused_today' => $today[$station->id]['refused'] ?? 0,
                'display_url' => route('attendance.qr.display', $station),
            ])->values(),

            'scans' => $recent->map(fn (AttendanceQrScan $scan): array => [
                'id' => $scan->id,
                'at' => $scan->created_at?->toIso8601String(),
                'station' => $scan->station?->name,
                'outcome' => $scan->outcome,
                'client' => $scan->client,
                'reason' => $scan->reason,
                'reason_label' => $scan->reason !== null ? (AttendanceQrScan::REASONS[$scan->reason] ?? $scan->reason) : null,
                'suspicious' => $scan->isSuspicious(),
                'punch_type' => $scan->punch_type,
                'distance_meters' => $scan->distance_meters,
                'accuracy' => $scan->accuracy !== null ? (int) round((float) $scan->accuracy) : null,
                'employee' => $scan->employee !== null ? [
                    'id' => $scan->employee->id,
                    'full_name' => $scan->employee->full_name,
                    'initials' => $scan->employee->initials(),
                    'employee_no' => $scan->employee->employee_no,
                    'photo' => $scan->employee->photo_url,
                ] : null,
            ])->values(),

            'options' => [
                'locations' => WorkLocation::query()->active()->orderBy('name')->get(['id', 'name']),
                'rotateChoices' => AttendanceQrStation::ROTATE_CHOICES,
            ],

            // Scanning without the app signs in with the kiosk PIN: how many
            // people have one, so HR knows to issue them before switching.
            'pins' => $manages ? [
                'issued' => EmployeeKioskPin::query()->whereHas('employee', fn ($query) => $query->whereNotIn('employment_status', ['resigned', 'terminated'])->orWhereNull('employment_status'))->count(),
                'employees' => Employee::query()->where(fn ($query) => $query->whereNotIn('employment_status', ['resigned', 'terminated'])->orWhereNull('employment_status'))->count(),
            ] : null,

            // Policies that would turn a QR punch away — why a good scan can
            // still be refused.
            'policiesWithoutQr' => AttendancePolicy::query()
                ->orderBy('name')
                ->get(['id', 'name', 'settings'])
                ->reject(fn (AttendancePolicy $policy): bool => in_array('qr', $policy->settings()->allowedSources, true))
                ->pluck('name')
                ->values(),

            'can' => [
                'manage' => $manages,
                'display' => $request->user()->can('attendance.qr.display'),
                'viewPolicies' => $request->user()->can('setup.attendance-policies.view'),
                'managePins' => $request->user()->can('attendance.kiosks.manage'),
            ],
        ];
    }

    /**
     * Each station's accepted and refused scans today, on the organisation's
     * clock.
     *
     * @param  list<int>  $ids
     * @return array<int, array{accepted: int, refused: int}>
     */
    private function scansToday(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $start = CarbonImmutable::parse(OrganizationClock::today(), OrganizationClock::timezone())->utc();
        $counts = [];

        AttendanceQrScan::query()
            ->whereIn('attendance_qr_station_id', $ids)
            ->where('created_at', '>=', $start)
            ->groupBy('attendance_qr_station_id', 'outcome')
            ->selectRaw('attendance_qr_station_id, outcome, count(*) as total')
            ->get()
            ->each(function ($row) use (&$counts): void {
                $counts[(int) $row->attendance_qr_station_id][(string) $row->outcome] = (int) $row->total;
            });

        return $counts;
    }
}

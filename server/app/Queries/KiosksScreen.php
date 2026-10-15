<?php

namespace App\Queries;

use App\Models\AttendanceKiosk;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\WorkLocation;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Attendance → Kiosks (ADR 0092): the company's kiosks with how each is doing,
 * the people who clock in at them, and what the screen's dialogs choose from.
 * Never a secret: pairing codes and PINs are answered once, by their own
 * endpoints, and only their hashes are stored.
 */
class KiosksScreen
{
    /**
     * The page's props, shaped as the client's `KiosksPageProps`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $kiosks = AttendanceKiosk::query()
            ->with('location:id,name,deleted_at')
            ->orderBy('name')
            ->get();

        $today = $this->punchesToday($kiosks->modelKeys());

        return [
            'kiosks' => $kiosks->map(fn (AttendanceKiosk $kiosk): array => [
                'hashid' => $kiosk->hashid,
                'name' => $kiosk->name,
                'location' => $kiosk->location !== null
                    ? ['id' => $kiosk->location->id, 'name' => $kiosk->location->name, 'archived' => $kiosk->location->trashed()]
                    : null,
                'status' => $kiosk->status(),
                'online' => $kiosk->isOnline(),
                'device_label' => $kiosk->device_label,
                'paired_at' => $kiosk->paired_at?->toIso8601String(),
                'last_seen_at' => $kiosk->last_seen_at?->toIso8601String(),
                'pairing_expires_at' => $kiosk->hasLivePairingCode() ? $kiosk->pairing_expires_at?->toIso8601String() : null,
                'punches_today' => $today[$kiosk->id] ?? 0,
            ])->values(),

            'pins' => EmployeeKioskPin::query()
                ->with([
                    'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,employment_status',
                    'employee.department:id,name',
                    'issuer:id,first_name,middle_name,last_name,suffix',
                ])
                ->whereHas('employee')
                ->get()
                ->sortBy(fn (EmployeeKioskPin $pin): string => mb_strtolower($pin->employee->full_name))
                ->map(fn (EmployeeKioskPin $pin): array => [
                    'id' => $pin->id,
                    'status' => $pin->status(),
                    'issued_at' => $pin->issued_at?->toIso8601String(),
                    'issued_by' => $pin->issuer?->full_name,
                    'changed_at' => $pin->changed_at?->toIso8601String(),
                    'last_used_at' => $pin->last_used_at?->toIso8601String(),
                    'locked_until' => $pin->isLocked() ? $pin->locked_until?->toIso8601String() : null,
                    'left' => in_array($pin->employee->employment_status, ['resigned', 'terminated'], true),
                    'employee' => [
                        'id' => $pin->employee->id,
                        'full_name' => $pin->employee->full_name,
                        'initials' => $pin->employee->initials(),
                        'employee_no' => $pin->employee->employee_no,
                        'photo' => $pin->employee->photo_url,
                        'department' => $pin->employee->department?->name,
                    ],
                ])
                ->values(),

            'options' => [
                'locations' => WorkLocation::query()->orderBy('name')->get(['id', 'name']),
                'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
                // Everybody who could be given a PIN. Whether they have the app
                // is the question HR is asking, so it is said.
                'employees' => Employee::query()
                    ->with(['invitations', 'kioskPin:id,employee_id'])
                    ->whereNotIn('employment_status', ['resigned', 'terminated'])
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get(['id', 'user_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'department_id', 'photo'])
                    ->map(fn (Employee $employee): array => [
                        'id' => $employee->id,
                        'full_name' => $employee->full_name,
                        'initials' => $employee->initials(),
                        'employee_no' => $employee->employee_no,
                        'department_id' => $employee->department_id,
                        'photo' => $employee->photo_url,
                        'app_access' => $employee->appAccess(),
                        'has_pin' => $employee->kioskPin !== null,
                    ]),
            ],

            // Policies that would turn a kiosk punch away — the reason a PIN
            // that works can still get "not allowed".
            'policiesWithoutKiosk' => AttendancePolicy::query()
                ->orderBy('name')
                ->get(['id', 'name', 'settings'])
                ->reject(fn (AttendancePolicy $policy): bool => in_array('kiosk', $policy->settings()->allowedSources, true))
                ->pluck('name')
                ->values(),

            'kioskUrl' => route('kiosk.show'),
            'can' => [
                'viewPolicies' => $request->user()->can('setup.attendance-policies.view'),
            ],
        ];
    }

    /**
     * How many punches each kiosk took today, on the organisation's clock.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function punchesToday(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $start = CarbonImmutable::parse(OrganizationClock::today(), OrganizationClock::timezone())->utc();

        return AttendancePunch::query()
            ->whereIn('attendance_kiosk_id', $ids)
            ->where('punched_at', '>=', $start)
            ->groupBy('attendance_kiosk_id')
            ->selectRaw('attendance_kiosk_id, count(*) as total')
            ->pluck('total', 'attendance_kiosk_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
}

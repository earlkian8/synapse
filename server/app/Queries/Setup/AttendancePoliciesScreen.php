<?php

namespace App\Queries\Setup;

use App\Http\Resources\AttendancePolicyResource;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Company Setup → Attendance Policies (ADR 0038): how a day is judged, chosen
 * from a preset and adjusted through typed options.
 */
class AttendancePoliciesScreen implements SetupScreen
{
    public function toArray(Request $request): array
    {
        return [
            'policies' => AttendancePolicyResource::collection($this->listing()->get())->resolve($request),
            'archivedPolicies' => AttendancePolicyResource::collection($this->listing()->onlyTrashed()->get())->resolve($request),
            'presets' => AttendancePolicyPresets::forClient(),
            // What a policy that sets nothing judges by — the built-in fallback,
            // which is where "start from scratch" begins.
            'fallback' => AttendancePolicySettings::fallback()->toArray(),
            'sources' => AttendancePunch::CAPTURE_SOURCES,
            'can' => ['manage' => $request->user()->can('setup.attendance-policies.manage')],
        ];
    }

    /**
     * @return Builder<AttendancePolicy>
     */
    private function listing(): Builder
    {
        return AttendancePolicy::query()
            ->withCount(['schedules', 'departments', 'assignments'])
            ->orderByDesc('is_default')
            ->orderBy('name');
    }
}

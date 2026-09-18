<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\AttendancePolicyPreviewRequest;
use App\Http\Requests\Setup\AttendancePolicyRequest;
use App\Http\Resources\AttendancePolicyResource;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\WorkedExample;
use App\Support\Hashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Attendance Policies (ADR 0038): how a day is judged — grace,
 * rounding, lateness thresholds, overtime, breaks, night differential — chosen
 * from a preset and adjusted through typed options.
 *
 * Addressed by hashid; restore / force-delete take it as a string. Archived
 * rather than hard-deleted, so a schedule or department that points at one keeps
 * resolving. Editing a policy never re-judges a day already recorded — HR
 * re-applies it from the attendance board when that is what they mean. Thin
 * controller: validation lives in {@see AttendancePolicyRequest}, the example in
 * {@see WorkedExample}.
 */
class AttendancePolicyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('setup/attendance-policies', [
            'policies' => AttendancePolicyResource::collection($this->listing()->get())->resolve($request),
            'archivedPolicies' => AttendancePolicyResource::collection($this->listing()->onlyTrashed()->get())->resolve($request),
            'presets' => AttendancePolicyPresets::forClient(),
            // What a policy that sets nothing judges by — the built-in fallback,
            // which is where "start from scratch" begins.
            'fallback' => AttendancePolicySettings::fallback()->toArray(),
            'sources' => AttendancePunch::CAPTURE_SOURCES,
            'can' => ['manage' => $request->user()->can('setup.attendance-policies.manage')],
        ]);
    }

    public function store(AttendancePolicyRequest $request): RedirectResponse
    {
        $policy = DB::transaction(function () use ($request): AttendancePolicy {
            $policy = AttendancePolicy::create($request->policyAttributes());
            $policy->enforceSingleDefault();

            return $policy;
        });

        ActivityLogger::log(
            event: 'created',
            description: "Created attendance policy \"{$policy->name}\"".($policy->is_default ? ' as the company default' : ''),
            subject: $policy,
            logName: 'company-setup',
            subjectLabel: $policy->name,
        );

        return $this->respond('Attendance policy created.');
    }

    public function update(AttendancePolicyRequest $request, AttendancePolicy $attendancePolicy): RedirectResponse
    {
        DB::transaction(function () use ($request, $attendancePolicy): void {
            $attendancePolicy->update($request->policyAttributes());
            $attendancePolicy->enforceSingleDefault();
        });

        ActivityLogger::log(
            event: 'updated',
            description: "Updated attendance policy \"{$attendancePolicy->name}\"",
            subject: $attendancePolicy,
            logName: 'company-setup',
            subjectLabel: $attendancePolicy->name,
        );

        return $this->respond('Attendance policy updated. Days already recorded keep the rules they were judged by.');
    }

    /**
     * Make a policy the company default — what anyone whose assignment, schedule
     * and department name none is judged by — or, when it already is, clear it,
     * which drops those people back to the built-in fallback.
     */
    public function setDefault(AttendancePolicy $attendancePolicy): RedirectResponse
    {
        $making = ! $attendancePolicy->is_default;

        DB::transaction(function () use ($attendancePolicy, $making): void {
            $attendancePolicy->forceFill(['is_default' => $making])->save();
            $attendancePolicy->enforceSingleDefault();
        });

        ActivityLogger::log(
            event: 'updated',
            description: $making
                ? "Set \"{$attendancePolicy->name}\" as the company's default attendance policy"
                : "Cleared \"{$attendancePolicy->name}\" as the company's default attendance policy",
            subject: $attendancePolicy,
            logName: 'company-setup',
            subjectLabel: $attendancePolicy->name,
        );

        return $this->respond($making
            ? "\"{$attendancePolicy->name}\" is now the company default."
            : 'Company default cleared — the built-in rules apply to anyone without a policy.');
    }

    public function destroy(AttendancePolicy $attendancePolicy): RedirectResponse
    {
        $name = $attendancePolicy->name;

        // An archived policy is no longer anybody's default; what points at it
        // directly keeps resolving to it.
        $attendancePolicy->forceFill(['is_default' => false])->save();
        $attendancePolicy->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived attendance policy \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Attendance policy archived.');
    }

    public function restore(string $attendancePolicy): RedirectResponse
    {
        $model = $this->findTrashed($attendancePolicy);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored attendance policy \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Attendance policy restored.');
    }

    public function forceDelete(string $attendancePolicy): RedirectResponse
    {
        $model = $this->findTrashed($attendancePolicy);

        if ($model->isInUse()) {
            return $this->respond('A schedule, department or assignment still uses this policy, so it cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted attendance policy \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Attendance policy permanently deleted.');
    }

    /**
     * The worked example: a sample day judged by the settings on screen, before
     * they are saved. Writes nothing.
     */
    public function preview(AttendancePolicyPreviewRequest $request): JsonResponse
    {
        return response()->json([
            'result' => WorkedExample::evaluate(
                AttendancePolicySettings::fromArray((array) $request->validated('settings')),
                (array) $request->validated('sample'),
            ),
        ]);
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

    private function findTrashed(string $hashid): AttendancePolicy
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return AttendancePolicy::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

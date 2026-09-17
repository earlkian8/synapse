<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\SetDefaultScheduleRequest;
use App\Http\Requests\Setup\WorkScheduleRequest;
use App\Models\WorkSchedule;
use App\Support\ActivityLogger;
use App\Support\Attendance\SchedulePatternWriter;
use App\Support\Hashid;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → Work Schedule & Holidays: the shift templates employees are
 * assigned to. Addressed by hashid; restore / force-delete take it as a string.
 * Archived rather than hard-deleted so assigned employees keep a schedule.
 *
 * A schedule's day pattern is written by {@see SchedulePatternWriter}, which also
 * keeps the pre-pattern summary columns true (ADR 0037). Thin controller.
 */
class WorkScheduleController extends Controller
{
    public function __construct(private readonly SchedulePatternWriter $patterns) {}

    public function store(WorkScheduleRequest $request): RedirectResponse
    {
        $schedule = WorkSchedule::create($request->safe()->except('days'));
        $this->patterns->write($schedule, $request->array('days'));

        ActivityLogger::log(
            event: 'created',
            description: "Created work schedule \"{$schedule->name}\"",
            subject: $schedule,
            logName: 'company-setup',
            subjectLabel: $schedule->name,
        );

        return $this->respond('Work schedule created.');
    }

    public function update(WorkScheduleRequest $request, WorkSchedule $workSchedule): RedirectResponse
    {
        $workSchedule->update($request->safe()->except('days'));
        $this->patterns->write($workSchedule, $request->array('days'));

        ActivityLogger::log(
            event: 'updated',
            description: "Updated work schedule \"{$workSchedule->name}\"",
            subject: $workSchedule,
            logName: 'company-setup',
            subjectLabel: $workSchedule->name,
        );

        return $this->respond('Work schedule updated.');
    }

    public function destroy(WorkSchedule $workSchedule): RedirectResponse
    {
        $name = $workSchedule->name;
        $workSchedule->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived work schedule \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Work schedule archived.');
    }

    public function restore(string $workSchedule): RedirectResponse
    {
        $model = $this->findTrashed($workSchedule);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored work schedule \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Work schedule restored.');
    }

    public function forceDelete(string $workSchedule): RedirectResponse
    {
        $model = $this->findTrashed($workSchedule);

        if ($model->employees()->exists()) {
            return $this->respond('This schedule is assigned to employees and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted work schedule \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Work schedule permanently deleted.');
    }

    /**
     * Choose the company's default hours — what anyone with no assignment and no
     * department default works (ADR 0037). Passing nothing clears it, which drops
     * those people to the built-in Mon–Fri fallback.
     */
    public function setDefault(SetDefaultScheduleRequest $request): RedirectResponse
    {
        $organization = app(Tenancy::class)->organization();

        abort_if($organization === null, 403);

        $schedule = $request->filled('work_schedule_id')
            ? WorkSchedule::findOrFail($request->integer('work_schedule_id'))
            : null;

        $organization->forceFill(['default_work_schedule_id' => $schedule?->id])->save();

        ActivityLogger::log(
            event: 'updated',
            description: $schedule !== null
                ? "Set \"{$schedule->name}\" as the company's default schedule"
                : "Cleared the company's default schedule",
            subject: $schedule,
            logName: 'company-setup',
            subjectLabel: $schedule?->name ?? 'Work schedules',
        );

        return $this->respond($schedule !== null
            ? "\"{$schedule->name}\" is now the company default."
            : 'Company default cleared.');
    }

    private function findTrashed(string $hashid): WorkSchedule
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return WorkSchedule::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

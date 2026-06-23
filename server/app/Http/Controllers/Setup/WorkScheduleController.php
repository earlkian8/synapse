<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\WorkScheduleRequest;
use App\Models\WorkSchedule;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → Work Schedule & Holidays: the work patterns (shifts) employees
 * are assigned to. Addressed by hashid; restore / force-delete take it as a
 * string. Archived rather than hard-deleted so assigned employees keep a schedule.
 * Thin controller.
 */
class WorkScheduleController extends Controller
{
    public function store(WorkScheduleRequest $request): RedirectResponse
    {
        $schedule = WorkSchedule::create($request->validated());

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
        $workSchedule->update($request->validated());

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

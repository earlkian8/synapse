<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\SetDefaultScheduleRequest;
use App\Http\Requests\Setup\WorkScheduleRequest;
use App\Models\WorkSchedule;
use App\Support\Hashid;
use App\Support\Setup\WorkScheduleException;
use App\Support\Setup\WorkScheduleWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → Work Schedule & Holidays: the shift templates employees are
 * assigned to. Addressed by hashid; restore / force-delete take it as a string.
 * Archived rather than hard-deleted so assigned employees keep a schedule.
 *
 * Thin controller: every write is {@see WorkScheduleWorkflow}, which the
 * assistant uses too, and whose day pattern is written by the one pattern
 * writer (ADR 0037).
 */
class WorkScheduleController extends Controller
{
    public function __construct(private readonly WorkScheduleWorkflow $workflow) {}

    public function store(WorkScheduleRequest $request): RedirectResponse
    {
        $this->workflow->create($request->safe()->except('days'), $request->array('days'));

        return $this->respond('Work schedule created.');
    }

    public function update(WorkScheduleRequest $request, WorkSchedule $workSchedule): RedirectResponse
    {
        $this->workflow->update($workSchedule, $request->safe()->except('days'), $request->array('days'));

        return $this->respond('Work schedule updated.');
    }

    public function destroy(WorkSchedule $workSchedule): RedirectResponse
    {
        $this->workflow->archive($workSchedule);

        return $this->respond('Work schedule archived.');
    }

    public function restore(string $workSchedule): RedirectResponse
    {
        $this->workflow->restore($this->findTrashed($workSchedule));

        return $this->respond('Work schedule restored.');
    }

    public function forceDelete(string $workSchedule): RedirectResponse
    {
        try {
            $this->workflow->forceDelete($this->findTrashed($workSchedule));
        } catch (WorkScheduleException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Work schedule permanently deleted.');
    }

    /**
     * Choose the company's default hours — what anyone with no assignment and no
     * department default works (ADR 0037). Passing nothing clears it, which drops
     * those people to the built-in Mon–Fri fallback.
     */
    public function setDefault(SetDefaultScheduleRequest $request): RedirectResponse
    {
        $schedule = $request->filled('work_schedule_id')
            ? WorkSchedule::findOrFail($request->integer('work_schedule_id'))
            : null;

        $this->workflow->setDefault($schedule);

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

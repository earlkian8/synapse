<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AssignScheduleRequest;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkSchedule;
use App\Support\ActivityLogger;
use App\Support\Attendance\ScheduleAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * An employee's schedule history (ADR 0037) — the profile's own way to put
 * somebody on a shift from a date, and to take an assignment back.
 *
 * It is the roster's bulk assign for one person, gated on the employee edit
 * permission rather than the roster's, because that is what this screen is: an
 * edit to this employee's record. Both go through {@see ScheduleAssigner}.
 */
class EmployeeScheduleController extends Controller
{
    public function __construct(private readonly ScheduleAssigner $assigner) {}

    /**
     * Put this employee on a schedule from a date.
     */
    public function store(AssignScheduleRequest $request, Employee $employee): RedirectResponse
    {
        $schedule = WorkSchedule::findOrFail($request->integer('work_schedule_id'));
        $from = $request->string('effective_from')->toString();

        $this->assigner->assign(
            $employee,
            $schedule,
            $from,
            $request->input('effective_to'),
            $request->integer('cycle_offset'),
            $request->user()->id,
        );

        $on = CarbonImmutable::parse($from)->format('M j, Y');

        ActivityLogger::log(
            event: 'updated',
            description: "Put {$employee->full_name} on \"{$schedule->name}\" from {$on}",
            subject: $employee,
            properties: ['work_schedule_id' => $schedule->id, 'effective_from' => $from],
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return $this->respond("{$employee->full_name} is on \"{$schedule->name}\" from {$on}.");
    }

    /**
     * Withdraw an assignment. Whoever it covered falls back to their department's
     * default schedule — or the company's — for those dates.
     */
    public function destroy(Employee $employee, EmployeeScheduleAssignment $assignment): RedirectResponse
    {
        abort_if($assignment->employee_id !== $employee->id, 404);

        $name = $assignment->workSchedule?->name ?? 'a schedule';
        $from = $assignment->effective_from->format('M j, Y');

        $this->assigner->withdraw($assignment);

        ActivityLogger::log(
            event: 'deleted',
            description: "Withdrew {$employee->full_name}'s assignment to \"{$name}\" from {$from}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Assignment withdrawn.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

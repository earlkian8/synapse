<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AssignScheduleRequest;
use App\Http\Requests\Attendance\RosterEntryRequest;
use App\Models\Employee;
use App\Models\ShiftRosterEntry;
use App\Models\WorkSchedule;
use App\Queries\ShiftRosterQuery;
use App\Support\ActivityLogger;
use App\Support\Attendance\RosterWriter;
use App\Support\Attendance\ScheduleAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The roster's write side (ADR 0037): one-off shift overrides, and putting people
 * on a schedule from a date. The board itself is a tab on
 * {@see AttendanceController::index()}, built by {@see ShiftRosterQuery}.
 *
 * Both actions go through the canonical writers, so the assistant and the
 * employee profile leave exactly the same history. Thin controller.
 */
class ShiftRosterController extends Controller
{
    public function __construct(
        private readonly RosterWriter $roster,
        private readonly ScheduleAssigner $assigner,
    ) {}

    /**
     * Set (or correct) one employee's shift for one date.
     */
    public function store(RosterEntryRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));
        $date = $request->string('date')->toString();

        $entry = $this->roster->set(
            $employee,
            $date,
            $request->safe()->only(['work_schedule_id', 'segments', 'required_minutes', 'is_rest_day', 'reason']),
            $request->user()->id,
        );

        $what = $entry->is_rest_day ? 'a rest day' : 'a different shift';

        ActivityLogger::log(
            event: 'updated',
            description: "Rostered {$employee->full_name} for {$what} on ".CarbonImmutable::parse($date)->format('M j'),
            subject: $entry,
            properties: ['date' => $date, 'reason' => $entry->reason],
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Shift override saved.');
    }

    /**
     * Clear an override, handing the day back to the employee's schedule.
     */
    public function destroy(ShiftRosterEntry $shiftRosterEntry): RedirectResponse
    {
        $shiftRosterEntry->load('employee:id,first_name,middle_name,last_name,suffix');
        $name = $shiftRosterEntry->employee?->full_name ?? 'employee';
        $date = $shiftRosterEntry->date->format('M j');

        $this->roster->clear($shiftRosterEntry);

        ActivityLogger::log(
            event: 'deleted',
            description: "Cleared {$name}'s shift override on {$date}",
            logName: 'attendance',
            subjectLabel: $name,
        );

        return $this->respond('Override cleared.');
    }

    /**
     * Put one or more people on a schedule from a date. Used by the roster's bulk
     * action and by the employee profile's "Assign schedule".
     */
    public function assign(AssignScheduleRequest $request): RedirectResponse
    {
        $schedule = WorkSchedule::findOrFail($request->integer('work_schedule_id'));
        $employees = Employee::whereIn('id', $request->array('employee_ids'))->get();
        $from = $request->string('effective_from')->toString();
        $to = $request->input('effective_to');

        foreach ($employees as $employee) {
            $this->assigner->assign(
                $employee,
                $schedule,
                $from,
                $to,
                $request->integer('cycle_offset'),
                $request->user()->id,
            );
        }

        $count = $employees->count();
        $who = $count === 1 ? $employees->first()->full_name : "{$count} employees";
        $period = CarbonImmutable::parse($from)->format('M j, Y')
            .($to !== null ? ' – '.CarbonImmutable::parse($to)->format('M j, Y') : ' onwards');

        ActivityLogger::log(
            event: 'updated',
            description: "Assigned \"{$schedule->name}\" to {$who} from {$period}",
            subject: $schedule,
            properties: ['employees' => $count, 'effective_from' => $from, 'effective_to' => $to],
            logName: 'attendance',
            subjectLabel: $schedule->name,
        );

        return $this->respond($count === 1
            ? "{$who} is on \"{$schedule->name}\" from ".CarbonImmutable::parse($from)->format('M j').'.'
            : "Assigned \"{$schedule->name}\" to {$count} employees.");
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

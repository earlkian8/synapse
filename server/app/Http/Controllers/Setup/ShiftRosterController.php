<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AssignScheduleRequest;
use App\Http\Requests\Attendance\RosterEntryRequest;
use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftRosterEntry;
use App\Models\WorkSchedule;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Queries\ShiftRosterQuery;
use App\Support\ActivityLogger;
use App\Support\Attendance\RosterWriter;
use App\Support\Attendance\ScheduleAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shift roster (ADR 0037) — who is due to work what, day by day. It is
 * configuration rather than a record: the plan Attendance judges each day
 * against, so it lives in Company Setup beside the schedules it is made of.
 *
 * The board is built by {@see ShiftRosterQuery}. Its writes — one-off shift
 * overrides, and putting people on a schedule from a date — go through the
 * canonical writers, so the assistant and the employee profile leave exactly
 * the same history. Thin controller.
 */
class ShiftRosterController extends Controller
{
    public function __construct(
        private readonly RosterWriter $roster,
        private readonly ScheduleAssigner $assigner,
    ) {}

    /**
     * The week containing `date` (the organisation's this week by default),
     * optionally narrowed to one department or a search.
     */
    public function index(Request $request, ShiftRosterQuery $roster, AttendanceRecordsIndexQuery $dates): Response
    {
        $date = $dates->date($request);
        $department = $request->integer('department') ?: null;
        $search = $request->string('search')->toString();

        return Inertia::render('setup/roster', [
            'roster' => fn () => $roster->toArray($date, $department, $search),
            'options' => [
                'departments' => Department::orderBy('name')->get(['id', 'name']),
                // The templates the override and assign dialogs choose from.
                'schedules' => WorkSchedule::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'type', 'cycle_length_days'])
                    ->map(fn (WorkSchedule $schedule): array => [
                        'id' => $schedule->id,
                        'name' => $schedule->name,
                        'type' => $schedule->type,
                        'cycle_length_days' => (int) $schedule->cycle_length_days,
                    ]),
                // What an assignment can single somebody out to be judged by (ADR 0038).
                'policies' => AttendancePolicy::query()->orderBy('name')->get(['id', 'name']),
            ],
            'can' => ['manage' => $request->user()->can('setup.roster.manage')],
            'filters' => [
                'date' => $date,
                'search' => $search,
                'department' => $department,
            ],
        ]);
    }

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
     * Put one or more people on a schedule from a date — the roster's bulk
     * action.
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
                $request->filled('attendance_policy_id') ? $request->integer('attendance_policy_id') : null,
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

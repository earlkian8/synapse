<?php

namespace App\Queries;

use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;

/**
 * Attendance → Shift Roster (ADR 0037, 0090): the week containing `date` (the
 * organisation's this week by default), optionally narrowed to one department or
 * a search, and the templates its dialogs choose from.
 */
class ShiftRosterScreen
{
    public function __construct(
        private readonly ShiftRosterQuery $roster,
        private readonly AttendanceRecordsIndexQuery $dates,
    ) {}

    /**
     * The roster page's props, shaped as the client's `RosterPageProps`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $date = $this->dates->date($request);
        $department = $request->integer('department') ?: null;
        $search = $request->string('search')->toString();

        return [
            'roster' => fn () => $this->roster->toArray($date, $department, $search),
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
            'can' => ['manage' => $request->user()->can('attendance.roster.manage')],
            'filters' => [
                'date' => $date,
                'search' => $search,
                'department' => $department,
            ],
        ];
    }
}

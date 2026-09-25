<?php

namespace App\Queries\Setup;

use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\WorkSchedule;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Queries\ShiftRosterQuery;
use Illuminate\Http\Request;

/**
 * Company Setup → Shift Roster (ADR 0037): the week containing `date` (the
 * organisation's this week by default), optionally narrowed to one department or
 * a search, and the templates its dialogs choose from.
 */
class ShiftRosterScreen implements SetupScreen
{
    public function __construct(
        private readonly ShiftRosterQuery $roster,
        private readonly AttendanceRecordsIndexQuery $dates,
    ) {}

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
            'can' => ['manage' => $request->user()->can('setup.roster.manage')],
            'filters' => [
                'date' => $date,
                'search' => $search,
                'department' => $department,
            ],
        ];
    }
}

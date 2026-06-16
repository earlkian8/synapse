<?php

namespace App\Queries;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The weekly grid: rows are employees, columns are the seven days of the week
 * that contains the anchor date (Mon–Sun), each cell a status. Built on the
 * shared {@see AttendanceRangeQuery}.
 */
class AttendanceWeeklyQuery
{
    public function __construct(private readonly AttendanceRangeQuery $range) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $anchor, ?int $department = null, string $search = ''): array
    {
        $start = CarbonImmutable::parse($anchor)->startOfWeek(CarbonInterface::MONDAY);
        $end = $start->addDays(6);
        $today = CarbonImmutable::today();

        $days = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'weekday' => $day->format('D'),
                'day' => $day->format('j'),
                'is_today' => $day->isSameDay($today),
                'is_future' => $day->gt($today),
            ];
        }

        $rows = $this->range
            ->days($start->toDateString(), $end->toDateString(), $department, $search)
            ->map(fn (array $row): array => [
                'employee' => $this->employee($row['employee']),
                'cells' => $row['cells'],
            ])
            ->values();

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employee(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'initials' => $employee->initials(),
            'employee_no' => $employee->employee_no,
            'photo' => $employee->photo_url,
            'department' => $employee->relationLoaded('department') && $employee->department
                ? ['id' => $employee->department->id, 'name' => $employee->department->name]
                : null,
        ];
    }
}

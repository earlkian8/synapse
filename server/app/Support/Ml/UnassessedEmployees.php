<?php

namespace App\Support\Ml;

use App\Models\Employee;

/**
 * Resolves a run's `unassessed` record — `[{employee_id, reason}]`, the employees a
 * model declined (ADR 0045) — into what the page lists: who, and why. Employees
 * deleted since the run drop out.
 */
class UnassessedEmployees
{
    /**
     * @param  list<array{employee_id: int, reason: string}>|null  $unassessed
     * @return list<array{reason: string, employee: array<string, mixed>}>
     */
    public static function resolve(?array $unassessed): array
    {
        if (empty($unassessed)) {
            return [];
        }

        $employees = Employee::query()
            ->whereIn('id', array_column($unassessed, 'employee_id'))
            ->with(['department:id,name', 'position:id,title'])
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'photo', 'department_id', 'position_id'])
            ->keyBy('id');

        $resolved = [];

        foreach ($unassessed as $entry) {
            $employee = $employees->get($entry['employee_id']);

            if ($employee === null) {
                continue;
            }

            $resolved[] = [
                'reason' => $entry['reason'],
                'employee' => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'initials' => $employee->initials(),
                    'employee_no' => $employee->employee_no,
                    'photo' => $employee->photo_url,
                    'position' => $employee->position?->title,
                    'department' => $employee->department?->name,
                ],
            ];
        }

        return $resolved;
    }
}

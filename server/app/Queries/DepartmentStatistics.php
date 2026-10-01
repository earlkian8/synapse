<?php

namespace App\Queries;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;

class DepartmentStatistics
{
    /**
     * Aggregate headline metrics for the org-structure overview.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'departments' => Department::count(),
            'positions' => Position::count(),
            'employees' => Employee::count(),
            'unheaded' => Department::whereNull('head_id')->count(),
        ];
    }
}

<?php

namespace App\Queries;

use App\Models\Employee;

class EmployeeStatistics
{
    /**
     * Aggregate headline metrics for the employee dashboard.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'total' => Employee::count(),
            'active' => Employee::where('employment_status', 'active')->count(),
            'regular' => Employee::where('employment_type', 'regular')->count(),
            'probationary' => Employee::where('employment_type', 'probationary')->count(),
            'on_leave' => Employee::where('employment_status', 'on_leave')->count(),
            'new_this_month' => Employee::where('date_hired', '>=', now()->startOfMonth())->count(),
            'archived' => Employee::onlyTrashed()->count(),
        ];
    }
}

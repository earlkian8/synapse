<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Queries\EmployeesIndexQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExportController extends Controller
{
    /**
     * Export the currently filtered employees as a CSV download.
     */
    public function __invoke(Request $request, EmployeesIndexQuery $query): StreamedResponse
    {
        $filename = 'employees-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Employee No', 'Full Name', 'Email', 'Phone', 'Department', 'Position',
            'Employment Type', 'Status', 'Date Hired', 'Basic Salary',
        ];

        return response()->stream(function () use ($query, $request, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->build($request)->chunk(200, function ($employees) use ($handle) {
                /** @var Employee $employee */
                foreach ($employees as $employee) {
                    fputcsv($handle, [
                        $employee->employee_no,
                        $employee->full_name,
                        $employee->email,
                        $employee->phone,
                        $employee->department?->name,
                        $employee->position?->title,
                        $employee->employment_type,
                        $employee->employment_status,
                        $employee->date_hired?->toDateString(),
                        $employee->basic_salary,
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}

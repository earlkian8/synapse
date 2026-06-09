<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Queries\UsersIndexQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserExportController extends Controller
{
    /**
     * Export the currently filtered users as a CSV download.
     */
    public function __invoke(Request $request, UsersIndexQuery $query): StreamedResponse
    {
        $filename = 'users-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Employee ID', 'First Name', 'Middle Name', 'Last Name', 'Suffix',
            'Email', 'Phone', 'Status', 'Email Verified', 'Last Login', 'Created At',
        ];

        return response()->stream(function () use ($query, $request, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->build($request)->chunk(200, function ($users) use ($handle) {
                /** @var User $user */
                foreach ($users as $user) {
                    fputcsv($handle, [
                        $user->employee_id,
                        $user->first_name,
                        $user->middle_name,
                        $user->last_name,
                        $user->suffix,
                        $user->email,
                        $user->phone_number,
                        $user->trashed() ? 'Archived' : ($user->is_active ? 'Active' : 'Inactive'),
                        $user->email_verified_at ? 'Yes' : 'No',
                        $user->last_login_at?->toDateTimeString(),
                        $user->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}

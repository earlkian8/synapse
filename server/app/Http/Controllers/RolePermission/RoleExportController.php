<?php

namespace App\Http\Controllers\RolePermission;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Queries\RolesIndexQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RoleExportController extends Controller
{
    /**
     * Export the currently filtered roles as a CSV download.
     */
    public function __invoke(Request $request, RolesIndexQuery $query): StreamedResponse
    {
        $filename = 'roles-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = ['Key', 'Label', 'Description', 'Type', 'Permissions', 'Members', 'Created At'];

        return response()->stream(function () use ($query, $request, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->build($request)->chunk(200, function ($roles) use ($handle) {
                /** @var Role $role */
                foreach ($roles as $role) {
                    fputcsv($handle, [
                        $role->name,
                        $role->label,
                        $role->description,
                        $role->is_system ? 'System' : 'Custom',
                        $role->permissions_count,
                        $role->users_count,
                        $role->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}

<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\ImportUsersRequest;
use App\Support\UserImporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk user creation from a CSV. `template` streams an example file to fill in;
 * `store` runs the import and returns a JSON result (created / failed counts plus
 * a per-row error list) so the import dialog can show an inline report without a
 * page navigation. Both are gated by `users.create`.
 */
class UserImportController extends Controller
{
    /**
     * Download a ready-to-fill CSV template with the recognised headers and one
     * example row.
     */
    public function template(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="users-import-template.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, UserImporter::COLUMNS);
            fputcsv($handle, [
                'Jane', 'Q', 'Dela Cruz', '', 'jane.delacruz@example.com',
                '+63 917 000 0000', 'EMP-1001', 'active', 'staff',
            ]);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Import users from the uploaded CSV and return the result.
     */
    public function store(ImportUsersRequest $request, UserImporter $importer): JsonResponse
    {
        $result = $importer->import($request->file('file'), $request->user());

        return response()->json($result);
    }
}

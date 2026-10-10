<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\ActivityLogger;

/**
 * Documents on an employee's 201 file (ADR 0068).
 *
 * The profile's upload form and the assistant both come through here, against
 * the same types and file rules, so a contract filed from chat is recorded the
 * same way as one uploaded on the screen. `$channel` is appended to the audit
 * description (" via assistant").
 */
class EmployeeDocuments
{
    /** What a document on file can be. */
    public const TYPES = ['contract', 'cv', 'govt_id', 'other'];

    /** Accepted formats and size cap (KB). */
    public const FILE_MIMES = 'pdf,jpg,jpeg,png,webp,doc,docx';

    public const FILE_MAX_KB = 10240;

    /**
     * Record a file already stored on the public disk as one of the employee's
     * documents.
     */
    public function add(Employee $employee, string $title, string $type, string $path, ?int $uploadedBy, string $channel = ''): EmployeeDocument
    {
        $document = $employee->documents()->create([
            'title' => $title,
            'type' => in_array($type, self::TYPES, true) ? $type : 'other',
            'file' => $path,
            'uploaded_by' => $uploadedBy,
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: "Added document \"{$title}\" to {$employee->full_name}{$channel}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return $document;
    }
}

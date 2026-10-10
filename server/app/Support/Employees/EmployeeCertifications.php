<?php

namespace App\Support\Employees;

use App\Http\Requests\Employee\StoreEmployeeCertificationRequest;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Support\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * An employee's certifications — licences, trainings, board exams — added and
 * removed (ADR 0059).
 *
 * The employee profile and the assistant both come through here, against
 * {@see StoreEmployeeCertificationRequest}. Both writes are recorded: removing
 * one used to leave no trace.
 *
 * A certificate earned by completing training (ADR 0075) belongs to Training:
 * it is withdrawn there, by undoing the completion, so the profile and the
 * assistant are refused here ({@see self::refusal()}).
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class EmployeeCertifications
{
    /**
     * @param  array{name: string, issuer?: string|null, issued_date?: string|null, expiry_date?: string|null}  $attributes
     */
    public function add(Employee $employee, array $attributes, ?UploadedFile $file = null, string $channel = ''): EmployeeCertification
    {
        $certification = $employee->certifications()->create([
            'name' => $attributes['name'],
            'issuer' => $attributes['issuer'] ?? null,
            'issued_date' => $attributes['issued_date'] ?? null,
            'expiry_date' => $attributes['expiry_date'] ?? null,
            'file' => $file?->store('employee-certifications', 'public'),
        ]);

        $this->log('updated', "Added certification \"{$certification->name}\" to {$employee->full_name}{$channel}", $employee);

        return $certification;
    }

    /**
     * Why a certification cannot be removed from the profile, or null if it can.
     */
    public function refusal(EmployeeCertification $certification): ?string
    {
        return $certification->fromTraining()
            ? 'This certificate was earned in training. To withdraw it, change the person’s enrollment in Training.'
            : null;
    }

    public function remove(Employee $employee, EmployeeCertification $certification, string $channel = ''): void
    {
        if ($certification->file) {
            Storage::disk('public')->delete($certification->file);
        }

        $name = $certification->name;
        $certification->delete();

        $this->log('updated', "Removed certification \"{$name}\" from {$employee->full_name}{$channel}", $employee);
    }

    private function log(string $event, string $description, Employee $employee): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );
    }
}

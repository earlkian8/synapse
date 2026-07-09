<?php

namespace App\Http\Requests\Training;

use App\Models\TrainingEnrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a single enrollment — its status, completion score and remarks. The
 * employee and program are fixed here; the completion timestamp is managed
 * server-side from the status, never trusted from the client. Enrolling people is
 * handled by {@see EnrollEmployeesRequest} (bulk), grading many at once by
 * {@see BulkEnrollmentRequest}.
 */
class TrainingEnrollmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(TrainingEnrollment::STATUSES)],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

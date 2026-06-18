<?php

namespace App\Http\Requests\Training;

use App\Models\TrainingEnrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enroll an employee in a program or update an enrollment. `employee_id` is only
 * required when enrolling (on update the employee is fixed); the completion
 * timestamp is managed server-side from the status, never trusted from the client.
 */
class TrainingEnrollmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $enrolling = $this->isMethod('post');

        return [
            'employee_id' => [Rule::requiredIf($enrolling), 'integer', Rule::exists('employees', 'id')],
            'status' => ['required', Rule::in(TrainingEnrollment::STATUSES)],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

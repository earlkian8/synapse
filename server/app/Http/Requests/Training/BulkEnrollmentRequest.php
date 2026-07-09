<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Apply one action to many enrollments at once from the roster: mark them
 * completed / dropped / (re)enrolled, or remove them. The completion timestamp is
 * derived from the resulting status server-side.
 */
class BulkEnrollmentRequest extends FormRequest
{
    /** The bulk actions the roster can apply. */
    public const ACTIONS = ['complete', 'drop', 'enroll', 'remove'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer', Rule::exists('training_enrollments', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enrollment_ids.required' => 'Select at least one enrollment.',
            'enrollment_ids.min' => 'Select at least one enrollment.',
        ];
    }
}

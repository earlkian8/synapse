<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update a position under a department.
 */
class PositionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'salary_grade_min' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'salary_grade_max' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'gte:salary_grade_min'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'salary_grade_max.gte' => 'The maximum salary must be greater than or equal to the minimum.',
        ];
    }
}

<?php

namespace App\Http\Requests\Performance;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ask colleagues to review an appraisal (ADR 0072): the employees to ask and,
 * optionally, when it is due. Each person is then checked on their own by the
 * workflow — an account to answer with, not the evaluator, not asked already.
 */
class RequestReviewsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reviewer_ids' => ['required', 'array', 'min:1', 'max:30'],
            'reviewer_ids.*' => ['integer', 'distinct', TenantRule::exists('employees')],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reviewer_ids.required' => 'Choose at least one person to ask.',
            'due_on.after_or_equal' => 'The due date can’t be in the past.',
        ];
    }
}

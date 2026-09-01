<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a candidate to a posting's pipeline — either an existing applicant
 * (`applicant_id`) or a new one created inline (name + optional details).
 */
class StoreJobApplicationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'applicant_id' => ['nullable', 'integer', Rule::exists('applicants', 'id')],

            // New applicant (when no applicant_id is supplied).
            'first_name' => ['required_without:applicant_id', 'nullable', 'string', 'max:255'],
            'last_name' => ['required_without:applicant_id', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'current_location' => ['nullable', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'portfolio_url' => ['nullable', 'url', 'max:255'],
            'years_experience' => ['nullable', 'integer', 'between:0,60'],
            'source' => ['nullable', Rule::in(StoreApplicantRequest::SOURCES)],

            // Application details.
            'expected_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cover_note' => ['nullable', 'string', 'max:5000'],
            'rating' => ['nullable', 'integer', 'between:1,5'],

            // Answers to the posting's own screening questions, keyed by question id.
            'screening_answers' => ['nullable', 'array'],
            'screening_answers.*' => ['boolean'],
        ];
    }
}

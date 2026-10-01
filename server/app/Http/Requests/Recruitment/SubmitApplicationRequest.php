<?php

namespace App\Http\Requests\Recruitment;

use App\Http\Controllers\Public\CareersController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A candidate applying through the public careers page (unauthenticated).
 *
 * Captures the applicant's profile, a required résumé, and optional supporting
 * documents. `hp_field` is a honeypot — see {@see CareersController}.
 */
class SubmitApplicationRequest extends FormRequest
{
    /** Anyone may apply — the route is public. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'current_location' => ['nullable', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'portfolio_url' => ['nullable', 'url', 'max:255'],
            'years_experience' => ['nullable', 'integer', 'between:0,60'],

            // Application-level details.
            'expected_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cover_note' => ['nullable', 'string', 'max:5000'],

            // The CV is required unless this posting opted out of asking for one.
            'resume' => [
                Rule::requiredIf(fn () => $this->route('jobPosting')?->requires_resume ?? true),
                'file', 'mimes:'.StoreApplicantRequest::FILE_MIMES, 'max:'.StoreApplicantRequest::FILE_MAX_KB,
            ],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*.type' => ['required_with:documents', Rule::in(StoreApplicantRequest::DOCUMENT_TYPES)],
            'documents.*.file' => ['required_with:documents', 'file', 'mimes:'.StoreApplicantRequest::FILE_MIMES, 'max:'.StoreApplicantRequest::FILE_MAX_KB],

            // Answers to the posting's own screening questions, keyed by question id.
            'screening_answers' => ['nullable', 'array'],
            'screening_answers.*' => ['boolean'],

            // Honeypot: real users never fill this. Validated loosely; the
            // controller silently drops a submission when it is present.
            'hp_field' => ['nullable', 'string'],
        ];
    }
}

<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicantRequest extends FormRequest
{
    public const SOURCES = ['website', 'referral', 'linkedin', 'agency', 'walk_in', 'other'];

    /** Supporting document categories an applicant may attach. */
    public const DOCUMENT_TYPES = ['cover_letter', 'certificate', 'transcript', 'portfolio', 'government_id', 'other'];

    /** Accepted upload formats / size cap (KB) for résumés and documents. */
    public const FILE_MIMES = 'pdf,doc,docx,jpg,jpeg,png';

    public const FILE_MAX_KB = 10240;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'current_location' => ['nullable', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'portfolio_url' => ['nullable', 'url', 'max:255'],
            'years_experience' => ['nullable', 'integer', 'between:0,60'],
            'source' => ['required', Rule::in(self::SOURCES)],
            'resume' => ['nullable', 'file', 'mimes:'.self::FILE_MIMES, 'max:'.self::FILE_MAX_KB],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Supporting files (cover letter, certificates, IDs, …).
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*.type' => ['required_with:documents', Rule::in(self::DOCUMENT_TYPES)],
            'documents.*.file' => ['required_with:documents', 'file', 'mimes:'.self::FILE_MIMES, 'max:'.self::FILE_MAX_KB],
        ];
    }
}

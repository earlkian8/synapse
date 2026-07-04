<?php

namespace App\Http\Requests\Awards;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ask the AI to draft a citation for one employee × one award type. Both must
 * exist; tenant scoping on the models keeps them within the organisation.
 */
class AwardCitationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'award_type_id' => ['required', 'integer', Rule::exists('award_types', 'id')],
        ];
    }
}

<?php

namespace App\Http\Requests\Awards;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'employee_id' => ['required', 'integer', TenantRule::exists('employees')],
            'award_type_id' => ['required', 'integer', TenantRule::exists('award_types')],
        ];
    }
}

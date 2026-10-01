<?php

namespace App\Http\Requests\Offboarding;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Append a clearance template's items to a case's checklist. The template must
 * belong to this workspace.
 */
class ApplyClearanceTemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offboarding_program_id' => ['required', 'integer', TenantRule::exists('offboarding_programs')],
        ];
    }
}

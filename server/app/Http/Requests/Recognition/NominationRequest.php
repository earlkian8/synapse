<?php

namespace App\Http\Requests\Recognition;

use App\Support\Recognition\NominationWorkflow;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Nominate a colleague for an award, with why (web or the mobile app). Whether
 * they and the award can be nominated is NominationWorkflow's call.
 */
class NominationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', TenantRule::exists('employees')],
            'award_type_id' => ['required', 'integer', TenantRule::exists('award_types')],
            'reason' => ['required', 'string', 'min:'.NominationWorkflow::MIN_REASON, 'max:'.NominationWorkflow::MAX_REASON],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.min' => 'Say why in at least 20 characters — it becomes the citation.'];
    }
}

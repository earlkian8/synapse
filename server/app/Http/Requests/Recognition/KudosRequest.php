<?php

namespace App\Http\Requests\Recognition;

use App\Support\Recognition\KudosWorkflow;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Send kudos to a colleague (web or the mobile app). Whether they can receive
 * it is KudosWorkflow's call.
 */
class KudosRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_employee_id' => ['required', 'integer', TenantRule::exists('employees')],
            'message' => ['required', 'string', 'max:'.KudosWorkflow::MAX_LENGTH],
        ];
    }
}

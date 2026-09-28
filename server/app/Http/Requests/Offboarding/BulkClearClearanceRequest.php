<?php

namespace App\Http\Requests\Offboarding;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sign off every pending item on a case at once — all of them, one department's,
 * or the unassigned ones.
 */
class BulkClearClearanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['all', 'department', 'unassigned'])],
            'department_id' => ['required_if:scope,department', 'nullable', 'integer', TenantRule::exists('departments')],
        ];
    }
}

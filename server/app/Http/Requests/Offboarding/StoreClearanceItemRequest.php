<?php

namespace App\Http\Requests\Offboarding;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or edit a single clearance sign-off on a case (its label, the owning
 * department, and any remarks).
 */
class StoreClearanceItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', TenantRule::exists('departments')],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

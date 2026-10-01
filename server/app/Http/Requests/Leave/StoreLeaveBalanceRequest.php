<?php

namespace App\Http\Requests\Leave;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Set one employee's leave entitlements for a year — a batch of
 * (leave type → entitled days) allocations that are upserted wholesale.
 */
class StoreLeaveBalanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = app(Tenancy::class)->id();

        return [
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employees', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'balances' => ['required', 'array'],
            'balances.*.leave_type_id' => [
                'required', 'integer',
                Rule::exists('leave_types', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'balances.*.entitled_days' => ['required', 'numeric', 'min:0', 'max:365'],
        ];
    }
}

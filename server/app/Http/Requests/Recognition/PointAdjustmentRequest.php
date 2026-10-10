<?php

namespace App\Http\Requests\Recognition;

use App\Support\Recognition\PointsLedger;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * HR's correction to a balance: an amount up or down, and the reason the
 * person is told.
 */
class PointAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = PointsLedger::MAX_ADJUSTMENT;

        return [
            'employee_id' => ['required', 'integer', TenantRule::exists('employees')],
            'amount' => ['required', 'integer', "between:-{$max},{$max}", 'not_in:0'],
            'note' => ['required', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\EmployeeDeduction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeDeduction
 */
class EmployeeDeductionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deduction_type_id' => $this->deduction_type_id,
            'name' => $this->whenLoaded('deductionType', fn () => $this->deductionType?->name),
            'amount' => (float) $this->amount,
            'is_active' => (bool) $this->is_active,
        ];
    }
}

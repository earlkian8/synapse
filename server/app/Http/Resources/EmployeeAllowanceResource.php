<?php

namespace App\Http\Resources;

use App\Models\EmployeeAllowance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeAllowance
 */
class EmployeeAllowanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'allowance_type_id' => $this->allowance_type_id,
            'name' => $this->whenLoaded('allowanceType', fn () => $this->allowanceType?->name),
            'amount' => (float) $this->amount,
            'is_active' => (bool) $this->is_active,
        ];
    }
}

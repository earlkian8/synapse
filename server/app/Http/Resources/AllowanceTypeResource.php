<?php

namespace App\Http\Resources;

use App\Models\AllowanceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AllowanceType
 */
class AllowanceTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'is_taxable' => (bool) $this->is_taxable,
            'usage_count' => (int) ($this->payslip_earnings_count ?? 0),
            'is_archived' => $this->deleted_at !== null,
        ];
    }
}

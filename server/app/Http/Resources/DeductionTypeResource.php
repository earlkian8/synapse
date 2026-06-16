<?php

namespace App\Http\Resources;

use App\Models\DeductionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeductionType
 */
class DeductionTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $computation = is_array($this->computation) ? $this->computation : [];
        $type = $computation['type'] ?? 'rate';

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'kind' => $this->kind,
            'is_mandatory' => (bool) $this->is_mandatory,
            'computation_type' => $type,
            // A flat-rate deduction exposes its rate (as a %) and optional cap; a
            // bracket deduction (e.g. withholding tax) is shown read-only.
            'rate' => $type === 'rate' ? round((float) ($computation['rate'] ?? 0) * 100, 2) : null,
            'cap' => isset($computation['max']) ? (float) $computation['max'] : null,
            'usage_count' => (int) ($this->payslip_deductions_count ?? 0),
            'is_archived' => $this->deleted_at !== null,
        ];
    }
}

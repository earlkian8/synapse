<?php

namespace App\Http\Resources;

use App\Models\PayrollPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayrollPeriod
 */
class PayrollPeriodResource extends JsonResource
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
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'pay_date' => $this->pay_date?->toDateString(),
            'status' => $this->status,
            'processed_by' => $this->whenLoaded('processor', fn () => $this->processor?->full_name),

            // Aggregates (loaded with withCount / withSum on the index query).
            'headcount' => (int) ($this->payslips_count ?? 0),
            'total_gross' => (float) ($this->payslips_sum_gross_pay ?? 0),
            'total_net' => (float) ($this->payslips_sum_net_pay ?? 0),
            'total_deductions' => (float) ($this->payslips_sum_total_deductions ?? 0),

            'payslips' => $this->whenLoaded(
                'payslips',
                fn () => PayslipResource::collection($this->payslips)->resolve($request),
            ),
        ];
    }
}

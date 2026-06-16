<?php

namespace App\Http\Resources;

use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\PayslipEarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payslip
 */
class PayslipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'status' => $this->status,

            'basic_pay' => (float) $this->basic_pay,
            'overtime_pay' => (float) $this->overtime_pay,
            'gross_pay' => (float) $this->gross_pay,
            'total_earnings' => (float) $this->total_earnings,
            'total_deductions' => (float) $this->total_deductions,
            'net_pay' => (float) $this->net_pay,
            'days_worked' => (float) $this->days_worked,

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'initials' => $this->employee->initials(),
                'employee_no' => $this->employee->employee_no,
                'photo' => $this->employee->photo_url,
                'position' => $this->employee->relationLoaded('position') && $this->employee->position
                    ? $this->employee->position->title
                    : null,
                'department' => $this->employee->relationLoaded('department') && $this->employee->department
                    ? $this->employee->department->name
                    : null,
            ] : null),

            'period' => $this->whenLoaded('period', fn () => $this->period ? [
                'name' => $this->period->name,
                'start_date' => $this->period->start_date?->toDateString(),
                'end_date' => $this->period->end_date?->toDateString(),
                'pay_date' => $this->period->pay_date?->toDateString(),
                'status' => $this->period->status,
            ] : null),

            'earnings' => $this->whenLoaded('earnings', fn () => $this->earnings
                ->map(fn (PayslipEarning $earning): array => [
                    'id' => $earning->id,
                    'label' => $earning->label,
                    'amount' => (float) $earning->amount,
                ])
                ->values()
                ->all()),

            'deductions' => $this->whenLoaded('deductions', fn () => $this->deductions
                ->map(fn (PayslipDeduction $deduction): array => [
                    'id' => $deduction->id,
                    'label' => $deduction->label,
                    'amount' => (float) $deduction->amount,
                    'kind' => $deduction->relationLoaded('deductionType') ? $deduction->deductionType?->kind : null,
                ])
                ->values()
                ->all()),
        ];
    }
}

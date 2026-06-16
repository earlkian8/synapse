<?php

namespace App\Http\Requests\Payroll;

use App\Support\Payroll\PayrollProcessor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a payroll run. The window's payslips are generated server-side from
 * attendance + salaries (see {@see PayrollProcessor}); the
 * request only carries the period's name and dates.
 */
class StorePayrollPeriodRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'pay_date' => ['required', 'date', 'after_or_equal:end_date'],
        ];
    }
}

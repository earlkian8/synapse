<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open a new performance evaluation for an employee within a period. The score
 * lines are seeded server-side from the active KPI criteria, so the client only
 * picks who and when. The employee / period existence is confined to the current
 * tenant by the models' global scope.
 */
class StorePerformanceEvaluationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'evaluation_period_id' => ['required', 'integer', Rule::exists('evaluation_periods', 'id')],
        ];
    }
}

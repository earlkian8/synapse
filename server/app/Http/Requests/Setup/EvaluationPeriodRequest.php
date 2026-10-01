<?php

namespace App\Http\Requests\Setup;

use App\Models\EvaluationPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an evaluation period (Company Setup → KPI & Evaluation
 * Criteria). The end date must not precede the start; the status defaults to
 * draft.
 */
class EvaluationPeriodRequest extends FormRequest
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
            'status' => ['required', Rule::in(EvaluationPeriod::STATUSES)],
        ];
    }
}

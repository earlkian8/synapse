<?php

namespace App\Http\Requests\Training;

use App\Models\TrainingCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record or change a cost on a program (ADR 0075): what it was for, how much,
 * and the day it was incurred — which decides the year it counts against.
 */
class TrainingCostRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(TrainingCost::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'incurred_on' => ['required', 'date_format:Y-m-d'],
        ];
    }
}

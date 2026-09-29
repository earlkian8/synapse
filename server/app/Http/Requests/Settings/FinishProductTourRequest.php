<?php

namespace App\Http\Requests\Settings;

use App\Support\ProductTour;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How somebody's first tour of the app ended — walked to the end, or skipped.
 * Either answer stops it being offered again ({@see ProductTour}).
 */
class FinishProductTourRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(ProductTour::OUTCOMES)],
        ];
    }
}

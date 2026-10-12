<?php

namespace App\Http\Requests\Settings;

use App\Models\ProductTourRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A tour run beginning (ADR 0080): which kind of run, and the stops it will
 * show, in order — what the per-stop numbers count against.
 */
class StartTourRunRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(ProductTourRun::MODES)],
            'steps' => ['required', 'array', 'min:1', 'max:30'],
            'steps.*' => ['string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
        ];
    }
}

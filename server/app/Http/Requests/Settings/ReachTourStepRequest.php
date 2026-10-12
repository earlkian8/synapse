<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A tour run moving to a step (ADR 0080), by its place in the run.
 */
class ReachTourStepRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'index' => ['required', 'integer', 'min:0', 'max:30'],
        ];
    }
}

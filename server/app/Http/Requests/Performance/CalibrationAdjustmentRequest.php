<?php

namespace App\Http\Requests\Performance;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Move one appraisal's rating in a calibration session (ADR 0073): to which band
 * of its own model, and why. The workflow checks the band belongs to the
 * appraisal's model and that the appraisal is part of the session.
 */
class CalibrationAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'evaluation_id' => ['required', 'integer', TenantRule::exists('performance_evaluations')],
            'band' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the rating moves — the reason stays on the record.',
            'reason.min' => 'Say why the rating moves — the reason stays on the record.',
        ];
    }
}

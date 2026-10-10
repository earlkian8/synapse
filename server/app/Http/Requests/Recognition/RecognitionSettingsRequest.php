<?php

namespace App\Http\Requests\Recognition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The points a kudos carries (0 turns kudos points off), and how many kudos a
 * month one person can give with points.
 */
class RecognitionSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kudos_points' => ['required', 'integer', 'min:0', 'max:1000'],
            'kudos_monthly_limit' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}

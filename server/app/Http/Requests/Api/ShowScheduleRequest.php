<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which week of one's own schedule to show (ADR 0083): any date in it, this week
 * when left out.
 */
class ShowScheduleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'week' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
        ];
    }
}

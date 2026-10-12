<?php

namespace App\Http\Requests\Offboarding;

use App\Support\OrganizationClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A resignation the employee files (ADR 0076): the last day they propose — not
 * in the past, on the organisation's calendar — and, if they want to say, why.
 * A notice shorter than the Labor Code's month is allowed; the screens warn.
 */
class FileResignationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * Static so the mobile API holds a resignation to exactly these.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(): array
    {
        return [
            'proposed_last_day' => ['required', Rule::date()->afterOrEqual(OrganizationClock::today())],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proposed_last_day.required' => 'Pick the last day you plan to work.',
            'proposed_last_day.after_or_equal' => 'Your last day can’t be in the past.',
        ];
    }
}

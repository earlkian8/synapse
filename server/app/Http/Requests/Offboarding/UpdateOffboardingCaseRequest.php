<?php

namespace App\Http\Requests\Offboarding;

use App\Models\OffboardingCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit an exit's details — its kind, the key dates and the reason. The last
 * working day cannot come before the notice was given. Authorization is the
 * route's `can:offboarding.manage`.
 */
class UpdateOffboardingCaseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(OffboardingCase::TYPES)],
            'notice_date' => ['nullable', 'date'],
            'last_working_day' => ['nullable', 'date', 'after_or_equal:notice_date'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

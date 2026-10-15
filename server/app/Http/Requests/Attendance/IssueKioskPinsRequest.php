<?php

namespace App\Http\Requests\Attendance;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Who to issue kiosk PINs to (ADR 0092) — one person, or a crew at once, whose
 * PINs are handed out on a printed sheet. Somebody who already has one is given
 * a fresh one, which also unlocks it.
 */
class IssueKioskPinsRequest extends FormRequest
{
    /** The most PINs one request issues — a sheet, not the whole company. */
    public const MAX = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            // Somebody who has left clocks in nowhere.
            'employee_ids.*' => ['integer', 'distinct', TenantRule::exists('employees')->whereNull('deleted_at')->whereNotIn('employment_status', ['resigned', 'terminated'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_ids.required' => 'Choose who to give a PIN to.',
            'employee_ids.max' => 'Issue up to '.self::MAX.' PINs at a time.',
            'employee_ids.*.exists' => 'Someone chosen is not on your roster, or has left.',
        ];
    }
}

<?php

namespace App\Http\Requests\Discipline;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The notice to explain (ADR 0105, D.O. 147-15): the specific charge and the
 * day an answer is due. The workflow holds the five calendar days.
 */
class IssueNoticeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notice_grounds' => ['required', 'string', 'min:20', 'max:3000'],
            'reply_due_on' => ['required', 'date'],
            'conduct_offense_id' => ['nullable', 'integer', TenantRule::exists('conduct_offenses')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notice_grounds.min' => 'Name the specific rule broken and how. A general description won’t do (D.O. 147-15).',
        ];
    }
}

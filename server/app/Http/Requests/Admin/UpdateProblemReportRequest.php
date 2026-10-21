<?php

namespace App\Http\Requests\Admin;

use App\Models\ProblemReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Following a problem report up (ADR 0096): where it stands, the reply its
 * reporter reads, and the note only admins read. An empty reply or note clears
 * it.
 */
class UpdateProblemReportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(ProblemReport::STATUSES))],
            'reply' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reply.max' => 'Keep the reply under 2,000 characters.',
            'internal_note.max' => 'Keep the note under 2,000 characters.',
        ];
    }
}

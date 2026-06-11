<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobPostingRequest extends FormRequest
{
    public const EMPLOYMENT_TYPES = ['regular', 'probationary', 'contractual', 'part_time'];

    public const STATUSES = ['draft', 'open', 'closed', 'filled'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')],
            'description' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'employment_type' => ['required', Rule::in(self::EMPLOYMENT_TYPES)],
            'openings' => ['required', 'integer', 'min:1', 'max:999'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'closing_date' => ['nullable', 'date'],
        ];
    }
}

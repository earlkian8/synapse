<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobPostingRequest extends FormRequest
{
    public const EMPLOYMENT_TYPES = ['regular', 'probationary', 'contractual', 'part_time'];

    public const STATUSES = ['draft', 'open', 'closed', 'filled'];

    /**
     * Whether a new closing date must be today or later. Creating a posting must
     * not back-date the deadline; editing an existing posting may leave a date
     * that has already passed untouched (see {@see UpdateJobPostingRequest}).
     */
    protected bool $enforceFutureClosing = true;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'recruitment_pipeline_id' => ['required', 'integer', Rule::exists('recruitment_pipelines', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')],
            'description' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            // Optional, position-aware screening criteria that shape the ranking.
            'min_years_experience' => ['nullable', 'integer', 'min:0', 'max:50'],
            'skills' => ['nullable', 'array', 'max:20'],
            'skills.*' => ['string', 'max:40'],
            'requires_resume' => ['boolean'],
            'use_fit_scoring' => ['boolean'],
            // Free-form yes/no screening questions — the generic complement to
            // min_years_experience/skills for anything those don't cover.
            'screening_questions' => ['array', 'max:20'],
            'screening_questions.*.label' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', Rule::in(self::EMPLOYMENT_TYPES)],
            'openings' => ['required', 'integer', 'min:1', 'max:999'],
            'status' => ['required', Rule::in(self::STATUSES)],
            // A published (open) posting must carry a deadline so candidates and
            // recruiters both know when applications close.
            'closing_date' => array_values(array_filter([
                'nullable',
                'date',
                'required_if:status,open',
                $this->enforceFutureClosing ? 'after_or_equal:today' : null,
            ])),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'closing_date.required_if' => 'An open posting needs a closing date so applicants know the deadline.',
            'closing_date.after_or_equal' => 'The closing date cannot be in the past.',
        ];
    }
}

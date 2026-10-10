<?php

namespace App\Http\Requests\Training;

use App\Models\TrainingAttendance;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Take attendance at a session (ADR 0074): a status per enrollment — present,
 * late, absent or excused — or none, to clear a mark. That every enrollment is
 * on this program, and that the day has come, is the workflow's to check.
 */
class TrainingAttendanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'marks' => ['required', 'array', 'min:1', 'max:500'],
            'marks.*.enrollment_id' => ['required', 'integer', 'distinct', TenantRule::exists('training_enrollments')],
            'marks.*.status' => ['nullable', Rule::in(TrainingAttendance::STATUSES)],
        ];
    }

    /**
     * The marks as enrollment id => status (null clears).
     *
     * @return array<int, string|null>
     */
    public function marks(): array
    {
        return collect($this->validated('marks'))
            ->mapWithKeys(fn (array $mark): array => [(int) $mark['enrollment_id'] => $mark['status'] ?? null])
            ->all();
    }
}

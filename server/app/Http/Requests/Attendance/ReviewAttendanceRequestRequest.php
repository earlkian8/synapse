<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deciding one attendance request (ADR 0039). Authorization is the route's
 * `can:attendance.requests.review`; whether this reviewer may decide *this*
 * request (not their own, not in a locked period) is the approver's to say.
 */
class ReviewAttendanceRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

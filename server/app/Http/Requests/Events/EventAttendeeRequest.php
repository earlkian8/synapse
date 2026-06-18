<?php

namespace App\Http\Requests\Events;

use App\Models\EventAttendee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invite employees to an event, or update one invitee's response. Inviting takes a
 * list of employees (events naturally invite many at once); updating changes a
 * single attendee's response. `employee_ids` is only required when inviting.
 */
class EventAttendeeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $inviting = $this->isMethod('post');

        return [
            'employee_ids' => [Rule::requiredIf($inviting), 'array'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')],
            'response' => [Rule::requiredIf(! $inviting), Rule::in(EventAttendee::RESPONSES)],
        ];
    }
}

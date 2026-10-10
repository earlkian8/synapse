<?php

namespace App\Http\Requests\Events;

use App\Models\EventAttendee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An invitee's own answer (web or the mobile app): going, maybe or not going,
 * for this date or — on a repeating event — every later one too.
 */
class RespondRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'response' => ['required', Rule::in(EventAttendee::ANSWERS)],
            'scope' => ['nullable', Rule::in(['this', 'following'])],
        ];
    }
}

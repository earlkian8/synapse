<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendNotificationRequest extends FormRequest
{
    /**
     * Audiences a notification may be broadcast to.
     *
     * @var list<string>
     */
    public const AUDIENCES = ['all', 'role', 'user'];

    /**
     * Severity levels (drive the icon / colour in the UI).
     *
     * @var list<string>
     */
    public const LEVELS = ['info', 'success', 'warning', 'error'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audience' => ['required', 'string', Rule::in(self::AUDIENCES)],
            'role_id' => ['required_if:audience,role', 'nullable', 'integer', 'exists:roles,id'],
            'user_id' => ['required_if:audience,user', 'nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:300'],
            'level' => ['required', 'string', Rule::in(self::LEVELS)],
        ];
    }
}

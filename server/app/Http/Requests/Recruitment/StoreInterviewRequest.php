<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterviewRequest extends FormRequest
{
    public const MODES = ['onsite', 'online', 'phone'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'interviewer_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'scheduled_at' => ['required', 'date'],
            'mode' => ['required', Rule::in(self::MODES)],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

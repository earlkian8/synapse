<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewLeaveRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The rules a decision is held to — by the Leave inbox and the assistant
     * alike.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

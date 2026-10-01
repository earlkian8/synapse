<?php

namespace App\Http\Requests\Help;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Help for this page" (ADR 0062): the address of the page somebody was on,
 * which must be one of the app's own — a path, never a URL elsewhere.
 */
class HelpForPageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:255', 'starts_with:/', 'not_regex:~^/[/\\\\]~'],
        ];
    }
}

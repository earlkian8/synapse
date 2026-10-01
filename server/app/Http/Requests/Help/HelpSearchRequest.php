<?php

namespace App\Http\Requests\Help;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A search of the Help Center (ADR 0062). Empty is allowed: the page then
 * offers the categories to browse instead of results.
 */
class HelpSearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** What was searched for, tidied. */
    public function searchTerm(): string
    {
        return trim((string) $this->validated('q', ''));
    }
}

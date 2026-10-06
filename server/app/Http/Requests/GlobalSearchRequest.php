<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A search from the ⌘K palette (ADR 0069). The palette asks only once two
 * characters are typed, so a shorter query is a mistake, not a search.
 */
class GlobalSearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ];
    }

    /** What was searched for, tidied. */
    public function searchTerm(): string
    {
        return trim((string) $this->validated('q'));
    }
}

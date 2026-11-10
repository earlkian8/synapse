<?php

namespace App\Http\Requests\CheckIns;

use App\Support\CheckIns\CheckInQuestions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * This month's check-in (ADR 0102): an answer to every question in
 * {@see CheckInQuestions}, each on its own scale, and an optional private note.
 */
class CheckInRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CheckInQuestions::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['*.required' => 'Answer every question — the check-in is saved only whole.'];
    }
}

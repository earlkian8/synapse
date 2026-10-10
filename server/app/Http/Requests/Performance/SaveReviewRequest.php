<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Save a reviewer's answers (ADR 0072): a rating and evidence per criterion — any
 * of them may be left blank — and the two written answers. Each rating is checked
 * against its own line's scale by the workflow.
 */
class SaveReviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scores' => ['present', 'array'],
            'scores.*.id' => ['required', 'integer'],
            'scores.*.score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'scores.*.remarks' => ['nullable', 'string', 'max:1000'],
            'strengths' => ['nullable', 'string', 'max:3000'],
            'improvements' => ['nullable', 'string', 'max:3000'],
        ];
    }
}

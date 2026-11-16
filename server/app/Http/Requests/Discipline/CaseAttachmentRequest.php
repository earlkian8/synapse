<?php

namespace App\Http\Requests\Discipline;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A piece of evidence for a case (ADR 0105): a photo, a PDF or a document,
 * up to 10 MB.
 */
class CaseAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Attach a photo, a PDF or a Word document.',
        ];
    }
}

<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An employee acknowledging their own appraisal (ADR 0072), with an optional
 * comment — where they can say they disagree.
 */
class AcknowledgeAppraisalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['comment' => ['nullable', 'string', 'max:2000']];
    }
}

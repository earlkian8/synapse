<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decline a review request, with an optional reason the evaluator reads.
 */
class DeclineReviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}

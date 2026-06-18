<?php

namespace App\Http\Requests\Performance;

use App\Support\Performance\PerformanceScorer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Save an evaluation's scorecard: the per-criterion ratings (1–5, nullable until
 * rated) and remarks, plus the overall remarks. Only lines belonging to the
 * evaluation are applied (verified in the controller); the overall score is
 * recomputed server-side, never trusted from the client.
 */
class UpdatePerformanceEvaluationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:2000'],
            'scores' => ['present', 'array'],
            'scores.*.id' => ['required', 'integer'],
            'scores.*.score' => ['nullable', 'numeric', 'min:'.PerformanceScorer::RATING_MIN, 'max:'.PerformanceScorer::RATING_MAX],
            'scores.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

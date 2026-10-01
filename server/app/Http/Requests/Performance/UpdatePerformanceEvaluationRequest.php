<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Save an evaluation's scorecard: the per-criterion ratings (nullable until
 * rated) and remarks, plus the overall remarks. Only lines belonging to the
 * evaluation are applied, and each score is checked against its own criterion's
 * rating scale in the controller (scales vary per criterion). The overall score
 * is recomputed server-side, never trusted from the client.
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
            // Broad sanity bound; the exact per-criterion scale is enforced in
            // the controller against each line's snapshot min/max.
            'scores.*.score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'scores.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

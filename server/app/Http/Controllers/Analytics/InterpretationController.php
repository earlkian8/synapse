<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AttritionRiskScore;
use App\Models\LocalModel;
use App\Models\PerformanceForecast;
use App\Models\PromotionReadinessScore;
use App\Support\ActivityLogger;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Ml\Interpretation\ModelCheckInterpreter;
use App\Support\Ml\Interpretation\PredictionInterpreter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plain-language readings an LLM writes for HR (ADR 0106): why the system scored one
 * person as it did, and what a model check means for the choice of model. Each is
 * written on demand, saved where it belongs — on the score, on the attempt — so the
 * next look costs nothing, and answered as JSON for the panel that asked. A failure
 * comes back as an "unavailable" reading, never an error.
 *
 * Routes need the surface's `*.view` permission and the `interpretations` rate
 * limit; every reading written is recorded in the activity log, since it sends part
 * of the record to the AI service.
 */
class InterpretationController extends Controller
{
    /** Each surface's per-person result. */
    private const SCORES = [
        'attrition' => AttritionRiskScore::class,
        'promotion' => PromotionReadinessScore::class,
        'performance' => PerformanceForecast::class,
    ];

    /**
     * Why one stored result is what it is. `{score}` is the result's id within the
     * surface; another workspace's 404s through the tenant scope.
     */
    public function score(Request $request, PredictionInterpreter $interpreter, int $score, string $surface): JsonResponse
    {
        $result = self::SCORES[$surface]::query()
            ->with(['run.localModel', 'employee'])
            ->findOrFail($score);

        abort_if($result->employee === null || $result->run === null, 404);

        $reading = $interpreter->interpret($surface, $result, $request->user());

        if ($reading['available'] ?? false) {
            $result->forceFill(['interpretation' => $reading])->save();

            ActivityLogger::log(
                event: 'interpreted',
                description: "Asked AI to explain {$result->employee->full_name}’s ".strtolower(ModelGraduation::SURFACES[$surface]['title']),
                subject: $result->employee,
                logName: ModelGraduation::SURFACES[$surface]['log'],
            );
        }

        return response()->json(['interpretation' => PredictionInterpreter::visible($reading, $request->user()) ?? $reading]);
    }

    /**
     * What one model check means. (Route parameters arrive in order — the URI's
     * `{localModel}`, then the `surface` default.)
     */
    public function model(ModelCheckInterpreter $interpreter, LocalModel $localModel, string $surface): JsonResponse
    {
        abort_unless($localModel->model === $surface, 404);

        $reading = $interpreter->interpret($localModel);

        if ($reading['available'] ?? false) {
            $localModel->forceFill(['interpretation' => $reading])->save();

            ActivityLogger::log(
                event: 'interpreted',
                description: 'Asked AI to explain the check of a '.ModelGraduation::SURFACES[$surface]['title'].' model trained on the organisation’s own records',
                subject: $localModel,
                logName: ModelGraduation::SURFACES[$surface]['log'],
            );
        }

        return response()->json(['interpretation' => $reading]);
    }
}

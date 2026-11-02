<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Ml\PredictionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What HR should weigh beside one person's prediction (ADR 0099): the person's trend
 * across runs, their results on the other predictive surfaces, and the record around
 * the model's inputs. Fetched by the breakdown dialog when it opens, so the page
 * itself stays light. The route checks the surface's view permission; each block is
 * gated by its own module's in {@see PredictionContext}.
 */
class PredictionContextController extends Controller
{
    public function __invoke(Request $request, PredictionContext $context, Employee $employee, string $surface): JsonResponse
    {
        return response()->json($context->for($employee, $surface, $request->user()));
    }
}

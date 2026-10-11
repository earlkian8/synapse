<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\FinishProductTourRequest;
use App\Http\Requests\Settings\ReachTourStepRequest;
use App\Http\Requests\Settings\StartTourRunRequest;
use App\Models\ProductTourRun;
use App\Support\ProductTour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The mobile app's tour (ADR 0080): a run starting, moving on, and ending —
 * which, when the tour was owed, records the answer so it is not offered again.
 * The same calls as the web's, on the app's own tour state.
 */
class TourController extends Controller
{
    public function start(StartTourRunRequest $request): JsonResponse
    {
        $run = ProductTour::startRun($request->user(), 'mobile', $request->validated('mode'), $request->validated('steps'));

        return response()->json(['id' => $run->id], 201);
    }

    public function reach(ReachTourStepRequest $request, ProductTourRun $run): Response
    {
        abort_unless($run->user_id === $request->user()->id && $run->platform === 'mobile', 404);

        ProductTour::reach($run, (int) $request->validated('index'));

        return response()->noContent();
    }

    public function finish(FinishProductTourRequest $request): Response
    {
        $outcome = $request->validated('outcome');

        if ($request->filled('run')) {
            $run = ProductTourRun::query()
                ->whereKey((int) $request->validated('run'))
                ->where('user_id', $request->user()->id)
                ->where('platform', 'mobile')
                ->first();

            if ($run !== null) {
                ProductTour::endRun($run, $outcome);
            }
        }

        if ($request->boolean('answers', true)) {
            ProductTour::finishMobile($request->user(), $outcome);
        }

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\FinishProductTourRequest;
use App\Http\Requests\Settings\ReachTourStepRequest;
use App\Http\Requests\Settings\StartTourRunRequest;
use App\Models\ProductTourRun;
use App\Support\ProductTour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The web tour's background calls (ADRs 0060, 0080): a run starting, moving on a
 * step, and ending — which, for the owed tour, records the answer so it is not
 * offered again.
 *
 * Called while the tour plays over a page, so they answer with JSON or no
 * content rather than a redirect: the page underneath must not reload. Not
 * activity-logged — like a profile edit, it is the person's own account state,
 * not a change to anything the company keeps.
 */
class ProductTourController extends Controller
{
    public function start(StartTourRunRequest $request): JsonResponse
    {
        $run = ProductTour::startRun($request->user(), 'web', $request->validated('mode'), $request->validated('steps'));

        return response()->json(['id' => $run->id], 201);
    }

    public function reach(ReachTourStepRequest $request, ProductTourRun $run): Response
    {
        abort_unless($run->user_id === $request->user()->id && $run->platform === 'web', 404);

        ProductTour::reach($run, (int) $request->validated('index'));

        return response()->noContent();
    }

    public function finish(FinishProductTourRequest $request): Response
    {
        $outcome = $request->validated('outcome');

        if ($request->filled('run')) {
            $this->endOwnRun($request, (int) $request->validated('run'), $outcome, 'web');
        }

        if ($request->boolean('answers', true)) {
            ProductTour::finish($request->user(), $outcome);
        }

        return response()->noContent();
    }

    private function endOwnRun(Request $request, int $id, string $outcome, string $platform): void
    {
        $run = ProductTourRun::query()->whereKey($id)->where('user_id', $request->user()->id)->where('platform', $platform)->first();

        if ($run !== null) {
            ProductTour::endRun($run, $outcome);
        }
    }
}

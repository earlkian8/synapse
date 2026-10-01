<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\RatingScaleRequest;
use App\Models\RatingScale;
use App\Support\Hashid;
use App\Support\Setup\PerformanceFrameworkException;
use App\Support\Setup\PerformanceFrameworkWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → Performance framework: the reusable rating scales criteria and
 * framework items are measured on. Addressed by hashid; restore / force-delete
 * take it as a string. Thin controller: every write is
 * {@see PerformanceFrameworkWorkflow}, which the assistant uses too.
 */
class RatingScaleController extends Controller
{
    public function __construct(private readonly PerformanceFrameworkWorkflow $workflow) {}

    public function store(RatingScaleRequest $request): RedirectResponse
    {
        $this->workflow->saveScale(null, $request->validated());

        return $this->respond('Rating scale created.');
    }

    public function update(RatingScaleRequest $request, RatingScale $ratingScale): RedirectResponse
    {
        $this->workflow->saveScale($ratingScale, $request->validated());

        return $this->respond('Rating scale updated.');
    }

    public function destroy(RatingScale $ratingScale): RedirectResponse
    {
        $this->workflow->archiveScale($ratingScale);

        return $this->respond('Rating scale archived.');
    }

    public function restore(string $ratingScale): RedirectResponse
    {
        $this->workflow->restoreScale($this->findTrashed($ratingScale));

        return $this->respond('Rating scale restored.');
    }

    public function forceDelete(string $ratingScale): RedirectResponse
    {
        try {
            $this->workflow->forceDeleteScale($this->findTrashed($ratingScale));
        } catch (PerformanceFrameworkException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Rating scale permanently deleted.');
    }

    private function findTrashed(string $hashid): RatingScale
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return RatingScale::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

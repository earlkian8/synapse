<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\RatingScaleRequest;
use App\Models\RatingScale;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → Performance framework: the reusable rating scales criteria and
 * framework items are measured on. Addressed by hashid; restore / force-delete
 * take it as a string. Thin controller.
 */
class RatingScaleController extends Controller
{
    public function store(RatingScaleRequest $request): RedirectResponse
    {
        $scale = RatingScale::create($request->validated());

        $this->settleDefault($scale);

        ActivityLogger::log(
            event: 'created',
            description: "Created rating scale \"{$scale->name}\"",
            subject: $scale,
            logName: 'company-setup',
            subjectLabel: $scale->name,
        );

        return $this->respond('Rating scale created.');
    }

    public function update(RatingScaleRequest $request, RatingScale $ratingScale): RedirectResponse
    {
        $ratingScale->update($request->validated());

        $this->settleDefault($ratingScale);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated rating scale \"{$ratingScale->name}\"",
            subject: $ratingScale,
            logName: 'company-setup',
            subjectLabel: $ratingScale->name,
        );

        return $this->respond('Rating scale updated.');
    }

    public function destroy(RatingScale $ratingScale): RedirectResponse
    {
        $name = $ratingScale->name;
        $ratingScale->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived rating scale \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Rating scale archived.');
    }

    public function restore(string $ratingScale): RedirectResponse
    {
        $model = $this->findTrashed($ratingScale);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored rating scale \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Rating scale restored.');
    }

    public function forceDelete(string $ratingScale): RedirectResponse
    {
        $model = $this->findTrashed($ratingScale);

        if ($model->criteria()->exists() || $model->items()->exists()) {
            return $this->respond('This scale is still in use and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted rating scale \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Rating scale permanently deleted.');
    }

    /**
     * A tenant has one preferred scale, so promoting one demotes the rest.
     */
    private function settleDefault(RatingScale $scale): void
    {
        if (! $scale->is_default) {
            return;
        }

        RatingScale::query()->whereKeyNot($scale->id)->update(['is_default' => false]);
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

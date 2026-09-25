<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\AwardTypeRequest;
use App\Models\AwardType;
use App\Queries\Setup\AwardTypesScreen;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Award Types: the catalogue of recognitions the Awards &
 * Recognition module gives out. Addressed by hashid; restore / force-delete take
 * it as a string. Thin controller.
 */
class AwardTypeController extends Controller
{
    public function index(Request $request, AwardTypesScreen $screen): Response
    {
        return Inertia::render('setup/award-types', $screen->toArray($request));
    }

    public function store(AwardTypeRequest $request): RedirectResponse
    {
        $type = AwardType::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Created award type \"{$type->name}\"",
            subject: $type,
            logName: 'company-setup',
            subjectLabel: $type->name,
        );

        return $this->respond('Award type created.');
    }

    public function update(AwardTypeRequest $request, AwardType $awardType): RedirectResponse
    {
        $awardType->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated award type \"{$awardType->name}\"",
            subject: $awardType,
            logName: 'company-setup',
            subjectLabel: $awardType->name,
        );

        return $this->respond('Award type updated.');
    }

    public function destroy(AwardType $awardType): RedirectResponse
    {
        $name = $awardType->name;
        $awardType->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived award type \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Award type archived.');
    }

    public function restore(string $awardType): RedirectResponse
    {
        $model = $this->findTrashed($awardType);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored award type \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Award type restored.');
    }

    public function forceDelete(string $awardType): RedirectResponse
    {
        $model = $this->findTrashed($awardType);

        if ($model->awards()->exists()) {
            return $this->respond('This award type has been given out and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted award type \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Award type permanently deleted.');
    }

    private function findTrashed(string $hashid): AwardType
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return AwardType::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

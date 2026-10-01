<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\AwardTypeRequest;
use App\Models\AwardType;
use App\Queries\Setup\AwardTypesScreen;
use App\Support\Hashid;
use App\Support\Setup\AwardTypeException;
use App\Support\Setup\AwardTypeWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Award Types: the catalogue of recognitions the Awards &
 * Recognition module gives out. Addressed by hashid; restore / force-delete take
 * it as a string. Thin controller: every write is {@see AwardTypeWorkflow},
 * which the assistant uses too.
 */
class AwardTypeController extends Controller
{
    public function __construct(private readonly AwardTypeWorkflow $workflow) {}

    public function index(Request $request, AwardTypesScreen $screen): Response
    {
        return Inertia::render('setup/award-types', $screen->toArray($request));
    }

    public function store(AwardTypeRequest $request): RedirectResponse
    {
        $this->workflow->create($request->validated());

        return $this->respond('Award type created.');
    }

    public function update(AwardTypeRequest $request, AwardType $awardType): RedirectResponse
    {
        $this->workflow->update($awardType, $request->validated());

        return $this->respond('Award type updated.');
    }

    public function destroy(AwardType $awardType): RedirectResponse
    {
        $this->workflow->archive($awardType);

        return $this->respond('Award type archived.');
    }

    public function restore(string $awardType): RedirectResponse
    {
        $this->workflow->restore($this->findTrashed($awardType));

        return $this->respond('Award type restored.');
    }

    public function forceDelete(string $awardType): RedirectResponse
    {
        try {
            $this->workflow->forceDelete($this->findTrashed($awardType));
        } catch (AwardTypeException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

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

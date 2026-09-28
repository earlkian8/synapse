<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\WorkLocationPeopleRequest;
use App\Http\Requests\Setup\WorkLocationRequest;
use App\Models\WorkLocation;
use App\Queries\Setup\LocationsScreen;
use App\Support\Hashid;
use App\Support\Setup\WorkLocationException;
use App\Support\Setup\WorkLocationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Locations (ADR 0040): the company's sites, each a fence drawn
 * on a map, who is based where, and the schedule and attendance policy people
 * based at a site default to.
 *
 * Addressed by hashid; restore / force-delete take it as a string. Archived
 * rather than deleted, so a punch that names a site keeps saying where it was.
 * Moving a fence never re-judges a punch already made — a punch was judged where
 * it was made. Thin controller: every write is {@see WorkLocationWorkflow},
 * which the assistant uses too.
 */
class WorkLocationController extends Controller
{
    public function __construct(private readonly WorkLocationWorkflow $workflow) {}

    public function index(Request $request, LocationsScreen $screen): Response
    {
        return Inertia::render('setup/locations', $screen->toArray($request));
    }

    public function store(WorkLocationRequest $request): RedirectResponse
    {
        $this->workflow->create($request->locationAttributes());

        return $this->respond('Location created. Add the people based there so their punches are checked against it.');
    }

    public function update(WorkLocationRequest $request, WorkLocation $workLocation): RedirectResponse
    {
        $this->workflow->update($workLocation, $request->locationAttributes());

        return $this->respond('Location updated. Punches already made keep where they were judged.');
    }

    /**
     * Set who is based here, and for whom it is the primary site. Anybody not
     * listed stops being based here; somebody made primary here stops having a
     * primary site anywhere else.
     */
    public function people(WorkLocationPeopleRequest $request, WorkLocation $workLocation): RedirectResponse
    {
        $ids = array_map('intval', $request->validated('employee_ids'));
        $primary = array_map('intval', $request->validated('primary_ids'));

        $this->workflow->setPeople($workLocation, $ids, $primary);

        return $this->respond(count($ids) === 1 ? '1 person is based here.' : count($ids).' people are based here.');
    }

    public function destroy(WorkLocation $workLocation): RedirectResponse
    {
        $this->workflow->archive($workLocation);

        return $this->respond('Location archived. Punches are no longer checked against it.');
    }

    public function restore(string $workLocation): RedirectResponse
    {
        $this->workflow->restore($this->findTrashed($workLocation));

        return $this->respond('Location restored.');
    }

    public function forceDelete(string $workLocation): RedirectResponse
    {
        try {
            $this->workflow->forceDelete($this->findTrashed($workLocation));
        } catch (WorkLocationException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Location permanently deleted.');
    }

    private function findTrashed(string $hashid): WorkLocation
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return WorkLocation::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\HolidayRequest;
use App\Models\Holiday;
use App\Support\Hashid;
use App\Support\Setup\HolidayWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → Work Schedule & Holidays: the organisation's holiday calendar.
 * Read by Leave (a non-working holiday is not charged as a leave day). Addressed
 * by hashid; restore / force-delete take it as a string. Thin controller: every
 * write is {@see HolidayWorkflow}, which the assistant uses too.
 */
class HolidayController extends Controller
{
    public function __construct(private readonly HolidayWorkflow $workflow) {}

    public function store(HolidayRequest $request): RedirectResponse
    {
        $this->workflow->create($request->validated());

        return $this->respond('Holiday added.');
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $this->workflow->update($holiday, $request->validated());

        return $this->respond('Holiday updated.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $this->workflow->archive($holiday);

        return $this->respond('Holiday archived.');
    }

    public function restore(string $holiday): RedirectResponse
    {
        $this->workflow->restore($this->findTrashed($holiday));

        return $this->respond('Holiday restored.');
    }

    public function forceDelete(string $holiday): RedirectResponse
    {
        $this->workflow->forceDelete($this->findTrashed($holiday));

        return $this->respond('Holiday permanently deleted.');
    }

    private function findTrashed(string $hashid): Holiday
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return Holiday::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

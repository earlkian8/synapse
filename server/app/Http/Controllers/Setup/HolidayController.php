<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\HolidayRequest;
use App\Models\Holiday;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → Work Schedule & Holidays: the organisation's holiday calendar.
 * Read by Leave (a non-working holiday is not charged as a leave day). Addressed
 * by hashid; restore / force-delete take it as a string. Thin controller.
 */
class HolidayController extends Controller
{
    public function store(HolidayRequest $request): RedirectResponse
    {
        $holiday = Holiday::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Added holiday \"{$holiday->name}\"",
            subject: $holiday,
            logName: 'company-setup',
            subjectLabel: $holiday->name,
        );

        return $this->respond('Holiday added.');
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $holiday->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated holiday \"{$holiday->name}\"",
            subject: $holiday,
            logName: 'company-setup',
            subjectLabel: $holiday->name,
        );

        return $this->respond('Holiday updated.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $name = $holiday->name;
        $holiday->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived holiday \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Holiday archived.');
    }

    public function restore(string $holiday): RedirectResponse
    {
        $model = $this->findTrashed($holiday);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored holiday \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Holiday restored.');
    }

    public function forceDelete(string $holiday): RedirectResponse
    {
        $model = $this->findTrashed($holiday);
        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted holiday \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

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

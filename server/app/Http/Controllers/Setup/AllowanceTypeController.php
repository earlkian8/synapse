<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\AllowanceTypeRequest;
use App\Models\AllowanceType;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → allowance types (the additional-earning kinds payslip lines can
 * be typed by). Addressed by hashid; restore / force-delete take it as a string.
 */
class AllowanceTypeController extends Controller
{
    public function store(AllowanceTypeRequest $request): RedirectResponse
    {
        $type = AllowanceType::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Created allowance type \"{$type->name}\"",
            subject: $type,
            logName: 'company-setup',
            subjectLabel: $type->name,
        );

        return $this->respond('Allowance type created.');
    }

    public function update(AllowanceTypeRequest $request, AllowanceType $allowanceType): RedirectResponse
    {
        $allowanceType->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated allowance type \"{$allowanceType->name}\"",
            subject: $allowanceType,
            logName: 'company-setup',
            subjectLabel: $allowanceType->name,
        );

        return $this->respond('Allowance type updated.');
    }

    public function destroy(AllowanceType $allowanceType): RedirectResponse
    {
        $name = $allowanceType->name;
        $allowanceType->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived allowance type \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Allowance type archived.');
    }

    public function restore(string $allowanceType): RedirectResponse
    {
        $model = $this->findTrashed($allowanceType);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored allowance type \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Allowance type restored.');
    }

    public function forceDelete(string $allowanceType): RedirectResponse
    {
        $model = $this->findTrashed($allowanceType);
        $name = $model->name;
        // Historical payslip lines keep their label; the FK is nulled on delete.
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted allowance type \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Allowance type permanently deleted.');
    }

    private function findTrashed(string $hashid): AllowanceType
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return AllowanceType::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

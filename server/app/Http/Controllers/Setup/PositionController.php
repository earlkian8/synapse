<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\PositionRequest;
use App\Models\Department;
use App\Models\Position;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PositionController extends Controller
{
    /**
     * Add a position under a department.
     */
    public function store(PositionRequest $request, Department $department): RedirectResponse
    {
        $position = $department->positions()->create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Added position \"{$position->title}\" to {$department->name}",
            subject: $department,
            logName: 'company-setup',
            subjectLabel: $department->name,
        );

        return $this->respond('Position added.');
    }

    /**
     * Update a position.
     */
    public function update(PositionRequest $request, Position $position): RedirectResponse
    {
        $position->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated position \"{$position->title}\"",
            subject: $position,
            logName: 'company-setup',
            subjectLabel: $position->title,
        );

        return $this->respond('Position updated.');
    }

    /**
     * Delete a position. Employees holding it keep their record (the FK nulls out).
     */
    public function destroy(Position $position): RedirectResponse
    {
        $title = $position->title;
        $position->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted position \"{$title}\"",
            logName: 'company-setup',
            subjectLabel: $title,
        );

        return $this->respond('Position deleted.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingProgramRequest;
use App\Models\TrainingProgram;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Manage training programs: create, edit, archive (soft delete), restore and
 * permanently delete. Created in-module (no Company-Setup config). Addressed by
 * hashid; restore / force-delete take it as a string. Thin (route gate
 * `training.manage`).
 */
class TrainingProgramController extends Controller
{
    public function store(TrainingProgramRequest $request): RedirectResponse
    {
        $program = TrainingProgram::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Created training program \"{$program->name}\"",
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        return $this->respond('Training program created.');
    }

    public function update(TrainingProgramRequest $request, TrainingProgram $trainingProgram): RedirectResponse
    {
        $trainingProgram->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated training program \"{$trainingProgram->name}\"",
            subject: $trainingProgram,
            logName: 'training',
            subjectLabel: $trainingProgram->name,
        );

        return $this->respond('Training program updated.');
    }

    public function destroy(TrainingProgram $trainingProgram): RedirectResponse
    {
        $name = $trainingProgram->name;
        $trainingProgram->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived training program \"{$name}\"",
            logName: 'training',
            subjectLabel: $name,
        );

        // The program's own page no longer resolves (soft-deleted), so land on the
        // overview rather than back() into a 404.
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Training program archived.']);

        return redirect()->route('training.index');
    }

    public function restore(string $trainingProgram): RedirectResponse
    {
        $model = $this->findTrashed($trainingProgram);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored training program \"{$model->name}\"",
            subject: $model,
            logName: 'training',
            subjectLabel: $model->name,
        );

        return $this->respond('Training program restored.');
    }

    public function forceDelete(string $trainingProgram): RedirectResponse
    {
        $model = $this->findTrashed($trainingProgram);

        if ($model->enrollments()->exists()) {
            return $this->respond('This program has enrollments and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted training program \"{$name}\"",
            logName: 'training',
            subjectLabel: $name,
        );

        return $this->respond('Training program permanently deleted.');
    }

    private function findTrashed(string $hashid): TrainingProgram
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return TrainingProgram::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

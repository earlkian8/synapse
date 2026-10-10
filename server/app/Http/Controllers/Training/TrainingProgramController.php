<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingProgramRequest;
use App\Models\TrainingProgram;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use App\Support\Training\TrainingWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Manage training programs: create, edit, archive (soft delete), restore and
 * permanently delete. Created in-module (no Company-Setup config). Addressed by
 * hashid; restore / force-delete take it as a string. Thin (route gate
 * `training.manage`): create, edit and archive go through
 * {@see TrainingWorkflow}, the path the assistant takes too.
 */
class TrainingProgramController extends Controller
{
    use TrainingResponses;

    public function store(TrainingProgramRequest $request, TrainingWorkflow $workflow): RedirectResponse
    {
        $workflow->create($request->validated());

        return $this->toast('Training program created.');
    }

    public function update(TrainingProgramRequest $request, TrainingProgram $trainingProgram, TrainingWorkflow $workflow): RedirectResponse
    {
        return $this->attempt(
            fn (): int => $workflow->update($trainingProgram, $request->validated()),
            fn (int $issued): string => $issued === 0
                ? 'Training program updated.'
                : "Training program updated. {$issued} ".str('certificate')->plural($issued).' issued to people who had completed it.',
        );
    }

    public function destroy(TrainingProgram $trainingProgram, TrainingWorkflow $workflow): RedirectResponse
    {
        $workflow->archive($trainingProgram);

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

        return $this->toast('Training program restored.');
    }

    public function forceDelete(string $trainingProgram): RedirectResponse
    {
        $model = $this->findTrashed($trainingProgram);

        if ($model->enrollments()->exists()) {
            return $this->toast('This program has enrollments and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted training program \"{$name}\"",
            logName: 'training',
            subjectLabel: $name,
        );

        return $this->toast('Training program permanently deleted.');
    }

    private function findTrashed(string $hashid): TrainingProgram
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return TrainingProgram::onlyTrashed()->findOrFail($id);
    }
}

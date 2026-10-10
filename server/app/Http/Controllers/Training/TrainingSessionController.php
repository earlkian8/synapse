<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingAttendanceRequest;
use App\Http\Requests\Training\TrainingSessionRequest;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Support\Training\TrainingSessions;
use Illuminate\Http\RedirectResponse;

/**
 * A program's sessions and the attendance taken at them (ADR 0074). Thin
 * (route gate `training.manage`): the rules — inside the program's dates, ends
 * after it starts, attendance from the day on and only for people holding a
 * seat — live in {@see TrainingSessions}, which the assistant uses too.
 * Sessions are addressed by numeric id.
 */
class TrainingSessionController extends Controller
{
    use TrainingResponses;

    public function __construct(private readonly TrainingSessions $sessions) {}

    public function store(TrainingSessionRequest $request, TrainingProgram $trainingProgram): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->sessions->create($trainingProgram, $request->validated()),
            fn ($created): string => $created->count() === 1 ? 'Session scheduled.' : "{$created->count()} sessions scheduled.",
        );
    }

    public function update(TrainingSessionRequest $request, TrainingSession $session): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->sessions->update($session, $request->safe()->except(['repeat_until', 'weekdays_only'])),
            'Session updated.',
        );
    }

    public function destroy(TrainingSession $session): RedirectResponse
    {
        $this->sessions->delete($session);

        return $this->toast('Session cancelled.');
    }

    public function attendance(TrainingAttendanceRequest $request, TrainingSession $session): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->sessions->mark($session, $request->marks(), $request->user()),
            'Attendance saved.',
        );
    }
}

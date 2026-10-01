<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StoreOnboardingTaskRequest;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Support\OnboardingTaskNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OnboardingTaskController extends Controller
{
    /**
     * Add an ad-hoc task to a case's checklist.
     */
    public function store(StoreOnboardingTaskRequest $request, OnboardingCase $case): RedirectResponse
    {
        $task = $case->tasks()->create([
            ...$request->validated(),
            'status' => 'pending',
            'sort_order' => (int) $case->tasks()->max('sort_order') + 1,
        ]);

        $case->touchProgress();
        OnboardingTaskNotifier::assigned($task, null, $request->user());

        return $this->respond('Task added.');
    }

    /**
     * Edit a task's details (title, category, assignee, due date).
     */
    public function update(StoreOnboardingTaskRequest $request, OnboardingTask $task): RedirectResponse
    {
        $previousAssignee = $task->assigned_to;
        $task->update($request->validated());

        OnboardingTaskNotifier::assigned($task, $previousAssignee, $request->user());

        return $this->respond('Task updated.');
    }

    /**
     * Set a task's status, stamping completion when it is marked done.
     */
    public function toggle(Request $request, OnboardingTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(OnboardingTask::STATUSES)],
        ]);

        $task->markStatus($validated['status'], $request->user()->id);

        $task->case->touchProgress();

        return $this->respond('Task updated.');
    }

    /**
     * Remove a task from the checklist.
     */
    public function destroy(OnboardingTask $task): RedirectResponse
    {
        $task->delete();

        return $this->respond('Task removed.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

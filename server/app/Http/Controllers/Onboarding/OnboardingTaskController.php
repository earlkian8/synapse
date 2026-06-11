<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StoreOnboardingTaskRequest;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Models\User;
use App\Support\Notifier;
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

        $this->touchCaseProgress($case);
        $this->notifyAssignee($task, null);

        return $this->respond('Task added.');
    }

    /**
     * Edit a task's details (title, category, assignee, due date).
     */
    public function update(StoreOnboardingTaskRequest $request, OnboardingTask $task): RedirectResponse
    {
        $previousAssignee = $task->assigned_to;
        $task->update($request->validated());

        $this->notifyAssignee($task, $previousAssignee);

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

        $done = $validated['status'] === 'done';

        $task->update([
            'status' => $validated['status'],
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $request->user()->id : null,
        ]);

        $this->touchCaseProgress($task->case);

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

    /**
     * Nudge a case from "pending" into "in_progress" once any work has happened,
     * so the board reflects activity without forcing a manual status change.
     */
    private function touchCaseProgress(OnboardingCase $case): void
    {
        if ($case->status !== 'pending') {
            return;
        }

        $hasActivity = $case->tasks()
            ->whereIn('status', ['in_progress', 'done', 'skipped'])
            ->exists();

        if ($hasActivity) {
            $case->update(['status' => 'in_progress']);
        }
    }

    /**
     * Notify a user when a task is newly assigned to them.
     */
    private function notifyAssignee(OnboardingTask $task, ?int $previousAssignee): void
    {
        if ($task->assigned_to === null || $task->assigned_to === $previousAssignee) {
            return;
        }

        $assignee = User::find($task->assigned_to);

        if (! $assignee) {
            return;
        }

        Notifier::toUser(
            $assignee,
            'Onboarding task assigned',
            "You were assigned \"{$task->title}\".",
            url: '/onboarding/'.$task->case->getRouteKey(),
            category: 'onboarding',
            actor: request()->user(),
        );
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

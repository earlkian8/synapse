<?php

namespace App\Support;

use App\Http\Controllers\Onboarding\OnboardingTaskController;
use App\Models\OnboardingTask;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The one place an onboarding checklist task talks to the person responsible for
 * it. Extracted from {@see OnboardingTaskController} so the checklist UI and the
 * assistant word, route and gate these notifications identically.
 *
 * Delivery itself belongs to {@see Notifier} (database + the recipient's opted-in
 * mail / push channels); this class only decides *when* and *what*.
 */
class OnboardingTaskNotifier
{
    /**
     * Tell a user a task is now theirs — but only when the assignee actually
     * changed, so an unrelated edit never re-pings them.
     */
    public static function assigned(OnboardingTask $task, ?int $previousAssignee = null, ?User $actor = null): int
    {
        if ($task->assigned_to === null || $task->assigned_to === $previousAssignee) {
            return 0;
        }

        $assignee = User::find($task->assigned_to);

        if (! $assignee) {
            return 0;
        }

        return Notifier::toUser(
            $assignee,
            'Onboarding task assigned',
            "You were assigned \"{$task->title}\".",
            url: self::url($task),
            category: 'onboarding',
            actor: $actor ?? request()?->user(),
        );
    }

    /**
     * Chase the assignee about an outstanding task. Unassigned tasks have nobody
     * to chase, so they are skipped rather than fanned out to the whole tenant.
     */
    public static function nudge(OnboardingTask $task, ?User $actor = null): int
    {
        $assignee = $task->assigned_to !== null ? User::find($task->assigned_to) : null;

        if (! $assignee) {
            return 0;
        }

        $employee = $task->case?->employee?->full_name;
        $due = $task->isOverdue()
            ? 'It was due '.$task->due_date->diffForHumans().'.'
            : ($task->due_date !== null ? 'It is due '.$task->due_date->diffForHumans().'.' : '');

        return Notifier::toUser(
            $assignee,
            'Onboarding task reminder',
            trim("\"{$task->title}\"".($employee !== null ? " for {$employee}" : '').' is still open. '.$due),
            url: self::url($task),
            level: $task->isOverdue() ? 'warning' : 'info',
            category: 'onboarding',
            actor: $actor ?? request()?->user(),
        );
    }

    /**
     * Chase a whole set of outstanding tasks. Grouped by assignee so a person with
     * four open items gets **one** reminder listing them, not four pings.
     * Unassigned tasks have nobody to chase and are skipped.
     *
     * @param  iterable<OnboardingTask>  $tasks
     * @return int The number of people reminded.
     */
    public static function nudgeMany(iterable $tasks, ?User $actor = null): int
    {
        $grouped = Collection::make($tasks)
            ->filter(fn (OnboardingTask $task): bool => $task->assigned_to !== null)
            ->groupBy('assigned_to');

        if ($grouped->isEmpty()) {
            return 0;
        }

        $assignees = User::whereIn('id', $grouped->keys())->get()->keyBy('id');
        $reminded = 0;

        foreach ($grouped as $assigneeId => $theirs) {
            $assignee = $assignees->get($assigneeId);

            if (! $assignee) {
                continue;
            }

            $first = $theirs->first();
            $titles = $theirs->take(4)->pluck('title')->implode(', ');
            $more = $theirs->count() - min($theirs->count(), 4);
            $employee = $first->case?->employee?->full_name;

            $reminded += Notifier::toUser(
                $assignee,
                'Onboarding tasks need attention',
                trim($theirs->count().' onboarding task'.($theirs->count() === 1 ? ' is' : 's are').' still open'
                    .($employee !== null ? " for {$employee}" : '').': '.$titles.($more > 0 ? " and {$more} more" : '').'.'),
                url: self::url($first),
                level: 'warning',
                category: 'onboarding',
                actor: $actor ?? request()?->user(),
            ) > 0 ? 1 : 0;
        }

        return $reminded;
    }

    /**
     * Deep link to the case the task lives on.
     */
    private static function url(OnboardingTask $task): ?string
    {
        $case = $task->case;

        return $case !== null ? '/onboarding/'.$case->getRouteKey() : null;
    }
}

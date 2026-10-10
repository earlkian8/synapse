<?php

namespace App\Support\Performance;

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\GoalCheckIn;
use App\Models\GoalTemplate;
use App\Models\PerformanceGoal;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Goals and their check-ins (ADR 0073) — the one path for setting a goal (for
 * one person or several, written out or from the library), editing it, checking
 * in on it, closing it and deleting a mistake. The screens, the self-service
 * pages and the assistant all come through here, and refuse for the same reasons
 * ({@see AppraisalException}).
 *
 * A goal started from the library **copies** the library's wording and target,
 * so retuning the library never moves a goal already set. A check-in is
 * append-only; the goal carries the latest value and health.
 */
class GoalWorkflow
{
    /**
     * Set the same goal for each of `$employees` in a cycle. HR may set goals in
     * any cycle that isn't closed (planning ahead included); someone setting
     * their own goal needs the cycle open.
     *
     * @param  iterable<int, Employee>  $employees
     * @param  array<string, mixed>  $data  title, description, measure, start_value, target_value, unit, weight, due_on.
     * @return list<PerformanceGoal>
     *
     * @throws AppraisalException
     */
    public function set(iterable $employees, EvaluationPeriod $period, array $data, ?GoalTemplate $template, ?User $by, bool $ownGoal = false, string $channel = ''): array
    {
        if ($period->status === 'closed') {
            throw new AppraisalException('Goals can’t be set in a closed cycle.');
        }

        if ($ownGoal && $period->status !== 'open') {
            throw new AppraisalException('You can add your own goals once the cycle is open.');
        }

        $fields = $this->fields($data, $template);
        $goals = [];

        DB::transaction(function () use ($employees, $period, $fields, $template, $by, &$goals): void {
            foreach ($employees as $employee) {
                if ($employee->employment_status !== 'active') {
                    throw new AppraisalException("{$employee->full_name} is not an active employee.");
                }

                $goals[] = PerformanceGoal::create([
                    ...$fields,
                    'employee_id' => $employee->id,
                    'evaluation_period_id' => $period->id,
                    'goal_template_id' => $template?->id,
                    'current_value' => $fields['start_value'],
                    'status' => 'active',
                    'created_by' => $by?->id,
                ]);
            }
        });

        if ($goals === []) {
            return [];
        }

        $first = $goals[0];
        $names = count($goals) === 1 ? $first->employee?->full_name : count($goals).' people';

        ActivityLogger::log(
            event: 'created',
            description: "Set the goal “{$first->title}” for {$names} ({$period->name}){$channel}",
            subject: count($goals) === 1 ? $first : $period,
            logName: 'performance',
            subjectLabel: count($goals) === 1 ? $first->employee?->full_name : $period->name,
        );

        foreach ($goals as $goal) {
            $this->tellOwner($goal, $by, 'New goal: '.$goal->title, ($by?->full_name ?? 'HR')." set you a goal for {$period->name}.");
        }

        return $goals;
    }

    /**
     * Change a goal's wording, target, weight or due date. Only while it is
     * active — a closed goal is a record.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AppraisalException
     */
    public function update(PerformanceGoal $goal, array $data, string $channel = ''): void
    {
        if (! $goal->isActive()) {
            throw new AppraisalException('A closed goal can’t be changed. Reopen it first.');
        }

        $fields = $this->fields($data + [
            'measure' => $goal->measure,
            'start_value' => $goal->start_value,
            'target_value' => $goal->target_value,
        ], null);

        // A goal that changed measure starts again from its new start.
        if ($fields['measure'] !== $goal->measure) {
            $fields['current_value'] = $fields['start_value'];
        }

        $goal->update($fields);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated the goal “{$goal->title}”{$channel}",
            subject: $goal,
            logName: 'performance',
            subjectLabel: $goal->employee?->full_name,
        );
    }

    /**
     * Record where a goal stands: a new value, how it is going, and a note. By
     * its owner, or by HR or a manager — in which case the owner is told.
     *
     * @throws AppraisalException
     */
    public function checkIn(PerformanceGoal $goal, float $value, string $health, ?string $note, ?User $by, string $channel = ''): GoalCheckIn
    {
        if (! $goal->isActive()) {
            throw new AppraisalException('Only an active goal can be checked in on.');
        }

        if ($goal->period?->status === 'closed') {
            throw new AppraisalException('The cycle is closed, so its goals can no longer be checked in on.');
        }

        if (! in_array($health, PerformanceGoal::HEALTHS, true)) {
            throw new AppraisalException('Say how the goal is going: on track, at risk or off track.');
        }

        if ($goal->measure === 'percent' && ($value < 0 || $value > 100)) {
            throw new AppraisalException('Progress is a percentage between 0 and 100.');
        }

        $note = trim((string) $note);

        $checkIn = DB::transaction(function () use ($goal, $value, $health, $note, $by): GoalCheckIn {
            $checkIn = $goal->checkIns()->create([
                'author_id' => $by?->id,
                'value' => $value,
                'health' => $health,
                'note' => $note === '' ? null : $note,
            ]);

            $goal->update([
                'current_value' => $value,
                'health' => $health,
                'last_check_in_at' => $checkIn->created_at,
            ]);

            return $checkIn;
        });

        ActivityLogger::log(
            event: 'updated',
            description: "Checked in on the goal “{$goal->title}” — ".GoalProgress::format($value, $goal->measure, $goal->unit)
                .', '.str_replace('_', ' ', $health).$channel,
            subject: $goal,
            logName: 'performance',
            subjectLabel: $goal->employee?->full_name,
        );

        $this->tellOwner($goal, $by, 'Check-in on your goal', ($by?->full_name ?? 'HR')." checked in on “{$goal->title}”: "
            .GoalProgress::format($value, $goal->measure, $goal->unit).', '.str_replace('_', ' ', $health).'.');

        return $checkIn;
    }

    /**
     * Close a goal as achieved, missed or dropped — or reopen a closed one.
     *
     * @throws AppraisalException
     */
    public function close(PerformanceGoal $goal, string $status, string $channel = ''): void
    {
        if (! in_array($status, PerformanceGoal::STATUSES, true)) {
            throw new AppraisalException('A goal is achieved, missed, dropped or active.');
        }

        if ($status === $goal->status) {
            throw new AppraisalException('The goal is already '.$status.'.');
        }

        if ($goal->period?->status === 'closed') {
            throw new AppraisalException('The cycle is closed, so its goals are final.');
        }

        $goal->update([
            'status' => $status,
            'closed_at' => $status === 'active' ? null : now(),
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: ($status === 'active' ? 'Reopened' : 'Marked '.$status)." the goal “{$goal->title}”{$channel}",
            subject: $goal,
            logName: 'performance',
            subjectLabel: $goal->employee?->full_name,
        );
    }

    /**
     * Delete a goal set by mistake. One with check-ins is history — drop it
     * instead. Someone deleting their own goal may only delete one they set.
     *
     * @throws AppraisalException
     */
    public function delete(PerformanceGoal $goal, ?User $by, bool $asOwner = false, string $channel = ''): void
    {
        if ($goal->checkIns()->exists()) {
            throw new AppraisalException('This goal has check-ins. Drop it instead, so its history is kept.');
        }

        if ($asOwner && $goal->created_by !== $by?->id) {
            throw new AppraisalException('Only goals you added yourself can be deleted here.');
        }

        $title = $goal->title;
        $name = $goal->employee?->full_name;
        $goal->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted the goal “{$title}” ({$name}){$channel}",
            logName: 'performance',
            subjectLabel: $name,
        );
    }

    /**
     * The goal's fields from what was asked, filled from the library entry where
     * nothing was said, and made coherent: a percentage goal runs 0 → 100, and a
     * number goal's target must differ from its start.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws AppraisalException
     */
    private function fields(array $data, ?GoalTemplate $template): array
    {
        $pick = fn (string $key, mixed $fallback = null): mixed => array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== ''
            ? $data[$key]
            : ($template?->{$key} ?? $fallback);

        $title = trim((string) ($data['title'] ?? $template?->name ?? ''));

        if ($title === '') {
            throw new AppraisalException('Give the goal a title.');
        }

        $measure = (string) $pick('measure', 'percent');

        if (! in_array($measure, PerformanceGoal::MEASURES, true)) {
            throw new AppraisalException('A goal is measured as a percentage or as a number.');
        }

        $start = $measure === 'percent' ? 0.0 : (float) $pick('start_value', 0);
        $target = $measure === 'percent' ? 100.0 : (float) $pick('target_value', 0);

        if ($measure === 'number' && $start === $target) {
            throw new AppraisalException('The target has to differ from where the goal starts.');
        }

        $fields = [
            'title' => mb_substr($title, 0, 255),
            'description' => $this->text($data['description'] ?? $template?->description),
            'measure' => $measure,
            'start_value' => $start,
            'target_value' => $target,
            'unit' => $measure === 'percent' ? null : $this->text($pick('unit')),
        ];

        if (array_key_exists('weight', $data) && $data['weight'] !== null && $data['weight'] !== '') {
            $fields['weight'] = max(0.1, (float) $data['weight']);
        }

        if (array_key_exists('due_on', $data)) {
            $fields['due_on'] = $data['due_on'] ?: null;
        }

        return $fields;
    }

    /**
     * Tell the goal's owner about a change someone else made.
     */
    private function tellOwner(PerformanceGoal $goal, ?User $by, string $title, string $body): void
    {
        $owner = $goal->employee?->user;

        if ($owner === null || ! $owner->is_active || $owner->id === $by?->id) {
            return;
        }

        Notifier::toUser($owner, $title, $body, '/performance/me/goals?goal='.$goal->hashid, 'info', 'performance', $by);
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

<?php

namespace App\Support\Performance;

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceScore;
use App\Models\ReviewTemplate;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes an appraisal, in one place: open one, rate it, submit
 * it, sign it off, discard a draft, or launch a whole review cycle.
 *
 * The Performance screens and the assistant both come through here, so an
 * appraisal is opened, rated and locked by the same rules — and refused for the
 * same reasons, in the same words ({@see AppraisalException}) — however the
 * request arrived. The result is always derived by {@see PerformanceScorer}, and
 * every rating is checked against its own line's frozen scale; nothing about
 * either is ever taken from the caller.
 *
 * `$channel` is appended to the audit description (" via assistant"), so the
 * trail says how a change was made as well as who made it.
 */
class AppraisalWorkflow
{
    public function __construct(
        private readonly EvaluationOpener $opener,
        private readonly PerformanceScorer $scorer,
        private readonly TemplateResolver $templates,
    ) {}

    /**
     * Open an appraisal for one employee in one cycle, seeded from the framework
     * named — or, when none is, from the one that covers them.
     *
     * @throws AppraisalException
     */
    public function open(Employee $employee, EvaluationPeriod $period, ?ReviewTemplate $template, ?User $by, string $channel = ''): PerformanceEvaluation
    {
        if ($reason = $this->opener->blockedReason($employee, $period)) {
            throw new AppraisalException($reason);
        }

        $template ??= $this->templates->forEmployee($employee);

        if ($template === null) {
            throw new AppraisalException('No appraisal framework covers this employee. Set one up under Company Setup.');
        }

        if ($template->items()->count() === 0) {
            throw new AppraisalException("“{$template->name}” has nothing to measure yet. Add its criteria first.");
        }

        $evaluation = $this->opener->open($employee, $period, $template, $by);

        ActivityLogger::log(
            event: 'created',
            description: "Opened a {$template->name} appraisal for {$employee->full_name} ({$period->name}){$channel}",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $employee->full_name,
        );

        return $evaluation;
    }

    /**
     * Save ratings (and remarks) onto a draft and recompute its result.
     *
     * `$lines` is keyed by score-line id; a line absent from it is left as it
     * was. Every rating given must be a value its own line's snapshot scale can
     * take — checked for all of them before any is written, so a bad one
     * changes nothing.
     *
     * @param  array<int, array{score?: float|int|string|null, remarks?: string|null}>  $lines
     * @param  bool  $setRemarks  Whether `$remarks` replaces the overall remarks (the screen always sends them).
     *
     * @throws AppraisalException
     */
    public function rate(PerformanceEvaluation $evaluation, array $lines, ?string $remarks = null, bool $setRemarks = false, string $channel = ''): ScoreResult
    {
        if (! $evaluation->isEditable()) {
            throw new AppraisalException('A submitted appraisal can no longer be edited.');
        }

        $incoming = collect($lines);
        $scores = $evaluation->scores()->get();

        foreach ($scores as $score) {
            $value = $incoming->get($score->id)['score'] ?? null;

            if ($value !== null && ! $score->acceptsScore((float) $value)) {
                throw new AppraisalException('A rating is outside its criterion’s scale.');
            }
        }

        $result = DB::transaction(function () use ($evaluation, $incoming, $scores, $remarks, $setRemarks): ScoreResult {
            foreach ($scores as $score) {
                /** @var PerformanceScore $score */
                if (! $incoming->has($score->id)) {
                    continue;
                }

                $line = $incoming->get($score->id);

                $score->update([
                    'score' => array_key_exists('score', $line) ? $line['score'] : $score->score,
                    'remarks' => array_key_exists('remarks', $line) ? $line['remarks'] : $score->remarks,
                ]);
            }

            $result = $this->scorer->score($evaluation->scores()->get(), $evaluation->bandList());
            $evaluation->applyResult($result);

            if ($setRemarks) {
                $evaluation->remarks = $remarks;
            }

            $evaluation->save();

            return $result;
        });

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated a performance scorecard'.$channel,
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        return $result;
    }

    /**
     * Lock the appraisal and finalise its result. Every line must be rated.
     *
     * @throws AppraisalException
     */
    public function submit(PerformanceEvaluation $evaluation, string $channel = ''): ScoreResult
    {
        if (! $evaluation->isEditable()) {
            throw new AppraisalException('This appraisal has already been submitted.');
        }

        $result = $this->scorer->score($evaluation->scores()->get(), $evaluation->bandList());

        if (! $result->isComplete()) {
            throw new AppraisalException('Rate every criterion before submitting.');
        }

        $evaluation->applyResult($result);
        $evaluation->status = 'submitted';
        $evaluation->submitted_at = now();
        $evaluation->save();

        ActivityLogger::log(
            event: 'submitted',
            description: "Submitted the appraisal for {$evaluation->employee?->full_name}"
                .($result->band ? " — {$result->band['label']}" : '').$channel,
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );

        return $result;
    }

    /**
     * Record the employee's sign-off on a submitted appraisal.
     *
     * @throws AppraisalException
     */
    public function acknowledge(PerformanceEvaluation $evaluation, string $channel = ''): void
    {
        if ($evaluation->status !== 'submitted') {
            throw new AppraisalException('Only a submitted appraisal can be acknowledged.');
        }

        $evaluation->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);

        ActivityLogger::log(
            event: 'acknowledged',
            description: "Acknowledged the appraisal for {$evaluation->employee?->full_name}{$channel}",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $evaluation->employee?->full_name,
        );
    }

    /**
     * Discard a draft. A submitted appraisal is a record and is kept.
     *
     * @throws AppraisalException
     */
    public function discard(PerformanceEvaluation $evaluation, string $channel = ''): void
    {
        if (! $evaluation->isEditable()) {
            throw new AppraisalException('A submitted appraisal cannot be deleted.');
        }

        $name = $evaluation->employee?->full_name;
        $evaluation->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted a draft appraisal for {$name}{$channel}",
            logName: 'performance',
            subjectLabel: $name,
        );
    }

    /**
     * Open the appraisals for a whole population — every active employee, or the
     * active staff of some departments. Idempotent: anyone already appraised in
     * the cycle is skipped, so it can be re-run as people join.
     *
     * @param  list<int>|null  $departmentIds  Null for everyone.
     *
     * @throws AppraisalException
     */
    public function launch(EvaluationPeriod $period, ?array $departmentIds, ?ReviewTemplate $pinned, ?User $by, string $channel = ''): CycleLaunch
    {
        if ($period->status !== 'open') {
            throw new AppraisalException('A cycle can only be launched while its review period is open.');
        }

        $pinned?->loadCount('items');

        if ($pinned !== null && $pinned->items_count === 0) {
            throw new AppraisalException("“{$pinned->name}” has nothing to measure yet. Add its criteria first.");
        }

        $employees = $this->population($departmentIds);

        if ($employees->isEmpty()) {
            throw new AppraisalException('No active employees are in that scope.');
        }

        $frameworks = $this->templates->active();
        $opened = 0;
        $skipped = 0;
        $uncovered = 0;

        foreach ($employees as $employee) {
            if ($this->opener->blockedReason($employee, $period) !== null) {
                $skipped++;

                continue;
            }

            $template = $pinned ?? $this->templates->forEmployee($employee, $frameworks);

            // A framework with nothing in it would seed an empty scorecard, which
            // can never be submitted — leave the person out and say so.
            if ($template === null || $template->items_count === 0) {
                $uncovered++;

                continue;
            }

            $this->opener->open($employee, $period, $template, $by);
            $opened++;
        }

        if ($opened > 0) {
            ActivityLogger::log(
                event: 'created',
                description: "Launched {$period->name}: opened {$opened} ".str('appraisal')->plural($opened).$channel,
                subject: $period,
                logName: 'performance',
                subjectLabel: $period->name,
            );
        }

        return new CycleLaunch($opened, $skipped, $uncovered);
    }

    /**
     * The active employees a launch draws in.
     *
     * @param  list<int>|null  $departmentIds
     * @return Collection<int, Employee>
     */
    private function population(?array $departmentIds): Collection
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('department_id', $departmentIds))
            ->orderBy('id')
            ->get();
    }
}

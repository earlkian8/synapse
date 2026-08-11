<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\LaunchReviewCycleRequest;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\ReviewTemplate;
use App\Support\ActivityLogger;
use App\Support\Performance\EvaluationOpener;
use App\Support\Performance\TemplateResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * Launching a review cycle: opening the appraisals for a whole population in one
 * action. Reviewing a company one "new evaluation" click at a time is the thing
 * that makes a performance module unusable above a dozen people.
 *
 * A launch is **idempotent by design** — anyone already appraised in the cycle is
 * skipped rather than duplicated, so it can be re-run as new hires land or as
 * more departments are brought into the cycle. Each employee is seeded from the
 * framework that covers them unless one is pinned for the whole launch.
 */
class PerformanceCycleController extends Controller
{
    public function __construct(
        private readonly EvaluationOpener $opener,
        private readonly TemplateResolver $templates,
    ) {}

    public function store(LaunchReviewCycleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $period = EvaluationPeriod::findOrFail($data['evaluation_period_id']);

        if ($period->status !== 'open') {
            return $this->back('A cycle can only be launched while its review period is open.', 'warning');
        }

        $pinned = isset($data['review_template_id'])
            ? ReviewTemplate::findOrFail($data['review_template_id'])->loadCount('items')
            : null;

        if ($pinned !== null && $pinned->items_count === 0) {
            return $this->back("“{$pinned->name}” has nothing to measure yet. Add its criteria first.", 'warning');
        }

        $employees = $this->population($data);

        if ($employees->isEmpty()) {
            return $this->back('No active employees are in that scope.', 'warning');
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

            $this->opener->open($employee, $period, $template, $request->user());
            $opened++;
        }

        if ($opened > 0) {
            ActivityLogger::log(
                event: 'created',
                description: "Launched {$period->name}: opened {$opened} ".str('appraisal')->plural($opened),
                subject: $period,
                logName: 'performance',
                subjectLabel: $period->name,
            );
        }

        return $this->back(...$this->outcome($opened, $skipped, $uncovered));
    }

    /**
     * The active employees a launch draws in.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Employee>
     */
    private function population(array $data): Collection
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->when(
                $data['scope'] === 'departments',
                fn ($query) => $query->whereIn('department_id', $data['department_ids'] ?? []),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * What to tell HR: how many appraisals were opened, and why anyone was left
     * out — a silent partial launch is worse than none.
     *
     * @return array{0: string, 1: string}
     */
    private function outcome(int $opened, int $skipped, int $uncovered): array
    {
        if ($opened === 0) {
            return $uncovered > 0
                ? ["No appraisals opened — {$uncovered} ".str('employee')->plural($uncovered).' are not covered by a framework.', 'warning']
                : ['Everyone in that scope already has an appraisal for this cycle.', 'info'];
        }

        $message = "Opened {$opened} ".str('appraisal')->plural($opened).'.';

        if ($skipped > 0) {
            $message .= " {$skipped} already had one.";
        }

        if ($uncovered > 0) {
            $message .= " {$uncovered} ".str('employee')->plural($uncovered).' had no framework.';
        }

        return [$message, 'success'];
    }

    private function back(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\AcknowledgeAppraisalRequest;
use App\Http\Resources\PerformanceEvaluationResource;
use App\Models\AppraisalReview;
use App\Models\AppraisalReviewScore;
use App\Models\PerformanceEvaluation;
use App\Support\Performance\AppraisalSharing;
use App\Support\Performance\AppraisalWorkflow;
use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\RatingScales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My appraisals (ADR 0072): the signed-in employee's own appraisals, read and
 * acknowledged by themselves. Gated by `performance.participate`.
 *
 * An appraisal shows its result only once it is **shared** — submitted, and not
 * held back by a calibration session. Before that it is listed as in progress or
 * being calibrated, and opening it is a 404, as is anyone else's. The employee
 * sees their own self-review beside the ratings; never anyone else's review.
 */
class MyPerformanceController extends Controller
{
    use PerformanceResponses;

    public function index(Request $request, AppraisalSharing $sharing): Response
    {
        $employee = $this->ownEmployee($request);

        $evaluations = $employee === null ? collect() : PerformanceEvaluation::query()
            ->forEmployee($employee)
            ->with(['period:id,name,status,start_date,end_date', 'evaluator:id,first_name,last_name'])
            ->latestFirst()
            ->get();

        $selfReviews = $employee === null ? collect() : AppraisalReview::query()
            ->byReviewer($employee)
            ->where('relationship', 'self')
            ->whereIn('performance_evaluation_id', $evaluations->pluck('id'))
            ->get()
            ->keyBy('performance_evaluation_id');

        return Inertia::render('performance/me', [
            'appraisals' => $evaluations->map(function (PerformanceEvaluation $e) use ($selfReviews, $sharing): array {
                $shared = $e->isShared();
                $self = $selfReviews->get($e->id);
                $band = $shared ? collect($e->bandList())->firstWhere('key', $e->result_band) : null;

                return [
                    'hashid' => $e->hashid,
                    'status' => $e->status,
                    'shared' => $shared,
                    // Submitted, but a calibration session is holding it back.
                    'calibrating' => $e->status === 'submitted' && ! $shared && $sharing->holdingSession($e) !== null,
                    'template_name' => $e->template_name,
                    'period' => $e->period ? [
                        'name' => $e->period->name,
                        'start_date' => $e->period->start_date?->toDateString(),
                        'end_date' => $e->period->end_date?->toDateString(),
                    ] : null,
                    'evaluator' => $e->evaluator?->full_name,
                    'result_label' => $shared ? $e->result_label : null,
                    'result_tone' => $band['tone'] ?? null,
                    'overall_percent' => $shared && $e->overall_percent !== null ? (float) $e->overall_percent : null,
                    'shared_at' => $e->shared_at?->toIso8601String(),
                    'acknowledged_at' => $e->acknowledged_at?->toIso8601String(),
                    'self_review' => $self ? [
                        'hashid' => $self->hashid,
                        'status' => $self->status,
                        'due_on' => $self->due_on?->toDateString(),
                        'open' => $self->isPending() && $e->isEditable(),
                    ] : null,
                ];
            })->values()->all(),
            'has_employee' => $employee !== null,
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    public function show(Request $request, PerformanceEvaluation $evaluation, PerformanceScorer $scorer): Response
    {
        $this->assertOwnShared($request, $evaluation);

        $evaluation->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,user_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'period:id,name,status,start_date,end_date',
            'evaluator:id,first_name,last_name',
            'acknowledger:id,first_name,last_name',
            'scores' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        $self = AppraisalReview::query()
            ->where('performance_evaluation_id', $evaluation->id)
            ->where('relationship', 'self')
            ->where('status', 'submitted')
            ->with('scores')
            ->first();

        $resource = (new PerformanceEvaluationResource($evaluation))->resolve($request);
        // HR's AI read is for the evaluator, not the employee.
        unset($resource['ai_insights']);

        return Inertia::render('performance/my-appraisal', [
            'evaluation' => $resource,
            'result' => $scorer->score($evaluation->scores, $evaluation->bandList())->toArray(),
            'selfReview' => $self === null ? null : [
                'strengths' => $self->strengths,
                'improvements' => $self->improvements,
                'submitted_at' => $self->submitted_at?->toIso8601String(),
                'lines' => $self->scores->mapWithKeys(function (AppraisalReviewScore $s) use ($evaluation): array {
                    $line = $evaluation->scores->firstWhere('id', $s->performance_score_id);

                    return [$s->performance_score_id => [
                        'score' => $s->score === null ? null : (float) $s->score,
                        'formatted' => $line === null ? null : RatingScales::format($s->score === null ? null : (float) $s->score, $line->scale()),
                        'remarks' => $s->remarks,
                    ]];
                })->all(),
            ],
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    public function acknowledge(AcknowledgeAppraisalRequest $request, PerformanceEvaluation $evaluation, AppraisalWorkflow $workflow): RedirectResponse
    {
        $this->assertOwnShared($request, $evaluation);

        return $this->attempt(
            fn () => $workflow->acknowledge($evaluation, by: $request->user(), comment: $request->validated('comment')),
            'Appraisal acknowledged.',
        );
    }

    /**
     * The employee's own appraisal, once it has been shared with them; otherwise
     * a 404.
     */
    private function assertOwnShared(Request $request, PerformanceEvaluation $evaluation): void
    {
        $employeeId = $request->user()->employee()->value('id');

        abort_unless(
            $employeeId !== null && (int) $employeeId === $evaluation->employee_id && $evaluation->isShared(),
            404,
        );
    }
}

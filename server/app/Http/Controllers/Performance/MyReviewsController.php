<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\DeclineReviewRequest;
use App\Http\Requests\Performance\SaveReviewRequest;
use App\Http\Resources\AppraisalReviewResource;
use App\Http\Resources\PerformanceScoreResource;
use App\Models\AppraisalReview;
use App\Models\AppraisalReviewScore;
use App\Support\Performance\ReviewWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reviews the signed-in person is asked to write (ADR 0072) — their self-review,
 * and reviews of colleagues as a manager, peer or direct report. Gated by
 * `performance.participate`; a review is reachable only by its reviewer (anyone
 * else gets a 404, as if it did not exist).
 *
 * The form shows the appraisal's criteria on their own scales — **never the
 * evaluator's ratings**, so a review is the reviewer's own view.
 */
class MyReviewsController extends Controller
{
    use PerformanceResponses;

    public function __construct(private readonly ReviewWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $employee = $this->ownEmployee($request);

        $reviews = $employee === null ? collect() : AppraisalReview::query()
            ->byReviewer($employee)
            ->where('status', '!=', 'cancelled')
            ->with([
                'evaluation:id,employee_id,evaluation_period_id,status,template_name',
                'evaluation.employee:id,first_name,middle_name,last_name,suffix,photo,department_id,position_id',
                'evaluation.employee.department:id,name',
                'evaluation.employee.position:id,title',
                'evaluation.period:id,name,start_date,end_date',
                'requester:id,first_name,last_name',
            ])
            ->latest('id')
            ->get();

        return Inertia::render('performance/reviews', [
            'reviews' => AppraisalReviewResource::collection($reviews)->resolve($request),
            'has_employee' => $employee !== null,
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    /**
     * The review form — or, once handed in, the answer as given.
     */
    public function show(Request $request, AppraisalReview $review): Response
    {
        $this->assertReviewer($request, $review);

        $review->load([
            'evaluation.employee:id,first_name,middle_name,last_name,suffix,photo,department_id,position_id',
            'evaluation.employee.department:id,name',
            'evaluation.employee.position:id,title',
            'evaluation.period:id,name,start_date,end_date',
            'evaluation.scores' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'requester:id,first_name,last_name',
            'scores',
        ]);

        $evaluation = $review->evaluation;

        // The criteria to answer, without what the evaluator has put on them.
        $criteria = collect(PerformanceScoreResource::collection($evaluation->scores)->resolve($request))
            ->map(fn (array $line): array => [...$line, 'score' => null, 'remarks' => null])
            ->values()
            ->all();

        return Inertia::render('performance/review', [
            'review' => (new AppraisalReviewResource($review))->resolve($request),
            'criteria' => $criteria,
            'sections' => $evaluation->template_sections ?? [],
            'answers' => $review->scores
                ->mapWithKeys(fn (AppraisalReviewScore $s): array => [$s->performance_score_id => [
                    'score' => $s->score === null ? null : (float) $s->score,
                    'remarks' => $s->remarks,
                ]])
                ->all(),
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    public function update(SaveReviewRequest $request, AppraisalReview $review): RedirectResponse
    {
        $this->assertReviewer($request, $review);
        $data = $request->validated();

        return $this->attempt(fn () => $this->workflow->answer(
            $review,
            $this->lines($data['scores']),
            $data['strengths'] ?? null,
            $data['improvements'] ?? null,
        ), 'Saved. You can come back to it before you submit.');
    }

    /**
     * Save what was sent, then hand it in — one action on the form.
     */
    public function submit(SaveReviewRequest $request, AppraisalReview $review): RedirectResponse
    {
        $this->assertReviewer($request, $review);
        $data = $request->validated();

        return $this->attempt(function () use ($review, $data): void {
            $this->workflow->answer($review, $this->lines($data['scores']), $data['strengths'] ?? null, $data['improvements'] ?? null);
            $this->workflow->submit($review->refresh());
        }, $review->isSelf() ? 'Self-review handed in.' : 'Review handed in. Thank you.');
    }

    public function decline(DeclineReviewRequest $request, AppraisalReview $review): RedirectResponse
    {
        $this->assertReviewer($request, $review);

        return $this->attempt(
            fn () => $this->workflow->decline($review, $request->validated('reason')),
            'Review declined. The evaluator has been told.',
        );
    }

    /**
     * @param  list<array{id: int, score?: float|int|string|null, remarks?: string|null}>  $scores
     * @return array<int, array{score: float|int|string|null, remarks: string|null}>
     */
    private function lines(array $scores): array
    {
        return collect($scores)
            ->keyBy('id')
            ->map(fn (array $line): array => ['score' => $line['score'] ?? null, 'remarks' => $line['remarks'] ?? null])
            ->all();
    }

    /**
     * Only the person asked can read or answer a review.
     */
    private function assertReviewer(Request $request, AppraisalReview $review): void
    {
        $employeeId = $request->user()->employee()->value('id');

        abort_unless($employeeId !== null && (int) $employeeId === $review->reviewer_id, 404);
    }
}

<?php

namespace App\Support\Performance;

use App\Models\AppraisalReview;
use App\Models\AppraisalReviewScore;
use App\Models\Employee;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Self, manager, peer and direct-report reviews of an appraisal (ADR 0072) — the
 * one path for asking for them, answering, declining, cancelling and reminding,
 * used by the screens, the cycle launch and the assistant alike. Refusals are
 * {@see AppraisalException}s, worded to be shown as they are.
 *
 * A review is **input**: the evaluator reads it beside the scorecard and it
 * never sets the result. Its ratings are taken on the appraisal's own lines, so
 * each is checked against the line's frozen scale exactly as the evaluator's are.
 */
class ReviewWorkflow
{
    /** Hours before the same request can be reminded again. */
    private const REMIND_EVERY_HOURS = 24;

    /**
     * Who `$reviewer` is to the person appraised — derived from the reporting
     * line, so the label can never be wrong.
     */
    public static function relationshipOf(Employee $reviewer, Employee $subject): string
    {
        return match (true) {
            $reviewer->id === $subject->id => 'self',
            $subject->manager_id !== null && $subject->manager_id === $reviewer->id => 'manager',
            $reviewer->manager_id !== null && $reviewer->manager_id === $subject->id => 'direct_report',
            default => 'peer',
        };
    }

    /**
     * Ask some people to review an appraisal. Each person is checked on their
     * own: the ones who can be asked are, and the rest come back with the reason
     * — so asking five people where one has no account still asks four.
     *
     * @param  iterable<int, Employee>  $reviewers
     * @return array{requested: list<AppraisalReview>, refused: array<string, string>}
     *
     * @throws AppraisalException When the appraisal itself cannot take reviews.
     */
    public function request(PerformanceEvaluation $evaluation, iterable $reviewers, ?User $by, ?string $dueOn = null, string $channel = ''): array
    {
        if (! $evaluation->isEditable()) {
            throw new AppraisalException('Reviews can only be asked for while the appraisal is in progress.');
        }

        $evaluation->loadMissing('employee', 'period');
        $subject = $evaluation->employee;

        if ($subject === null) {
            throw new AppraisalException('This appraisal no longer has an employee.');
        }

        $due = $this->dueDate($evaluation, $dueOn);
        $requested = [];
        $refused = [];

        foreach ($reviewers as $reviewer) {
            $reason = $this->refusal($evaluation, $reviewer);

            if ($reason !== null) {
                $refused[$reviewer->full_name] = $reason;

                continue;
            }

            $requested[] = $this->ask($evaluation, $reviewer, $subject, $by, $due);
        }

        if ($requested !== []) {
            ActivityLogger::log(
                event: 'created',
                description: 'Asked '.count($requested).' '.str('person')->plural(count($requested))
                    ." to review {$subject->full_name}'s appraisal{$channel}",
                subject: $evaluation,
                logName: 'performance',
                subjectLabel: $subject->full_name,
            );
        }

        return ['requested' => $requested, 'refused' => $refused];
    }

    /**
     * Why `$reviewer` cannot be asked to review this appraisal — or null when
     * they can.
     */
    public function refusal(PerformanceEvaluation $evaluation, Employee $reviewer): ?string
    {
        if ($reviewer->employment_status !== 'active') {
            return "{$reviewer->full_name} is not an active employee.";
        }

        if ($this->answerer($reviewer) === null) {
            return "{$reviewer->full_name} has no account that can write reviews.";
        }

        if ($evaluation->evaluator_id !== null && $reviewer->user_id === $evaluation->evaluator_id) {
            return "{$reviewer->full_name} is conducting this appraisal; they rate the scorecard itself.";
        }

        $existing = AppraisalReview::query()
            ->where('performance_evaluation_id', $evaluation->id)
            ->where('reviewer_id', $reviewer->id)
            ->value('status');

        return match ($existing) {
            'pending' => "{$reviewer->full_name} has already been asked.",
            'submitted' => "{$reviewer->full_name} has already reviewed it.",
            default => null,
        };
    }

    /**
     * Save a reviewer's answers — any part of them, as often as they like, while
     * the review is pending and the appraisal is still in progress.
     *
     * @param  array<int, array{score?: float|int|string|null, remarks?: string|null}>  $lines  Keyed by appraisal line id.
     *
     * @throws AppraisalException
     */
    public function answer(AppraisalReview $review, array $lines, ?string $strengths, ?string $improvements): void
    {
        $this->assertAnswerable($review);

        $appraisalLines = $review->evaluation->scores()->get()->keyBy('id');

        foreach ($lines as $lineId => $line) {
            $appraisalLine = $appraisalLines->get((int) $lineId);

            if ($appraisalLine === null) {
                throw new AppraisalException('A rating is for a criterion that isn’t on this appraisal.');
            }

            $value = $line['score'] ?? null;

            if ($value !== null && $value !== '' && ! $appraisalLine->acceptsScore((float) $value)) {
                throw new AppraisalException("The rating for “{$appraisalLine->label}” is outside its scale.");
            }
        }

        DB::transaction(function () use ($review, $lines, $strengths, $improvements): void {
            foreach ($lines as $lineId => $line) {
                $value = $line['score'] ?? null;

                AppraisalReviewScore::updateOrCreate(
                    ['appraisal_review_id' => $review->id, 'performance_score_id' => (int) $lineId],
                    [
                        'score' => $value === null || $value === '' ? null : (float) $value,
                        'remarks' => $this->text($line['remarks'] ?? null),
                    ],
                );
            }

            $review->update([
                'strengths' => $this->text($strengths),
                'improvements' => $this->text($improvements),
            ]);
        });
    }

    /**
     * Hand the review in. A self-review rates every criterion; anyone else's
     * needs at least one rating or one written answer — leaving a criterion blank
     * is how a reviewer says they can't judge it.
     *
     * @throws AppraisalException
     */
    public function submit(AppraisalReview $review, string $channel = ''): void
    {
        $this->assertAnswerable($review);

        $rated = $review->scores()->whereNotNull('score')->count();
        $total = $review->evaluation->scores()->count();
        $written = trim((string) $review->strengths) !== '' || trim((string) $review->improvements) !== '';

        if ($review->isSelf() && $rated < $total) {
            throw new AppraisalException('Rate every criterion before handing in your self-review.');
        }

        if (! $review->isSelf() && $rated === 0 && ! $written) {
            throw new AppraisalException('Rate at least one criterion or write an answer before submitting.');
        }

        $review->update(['status' => 'submitted', 'submitted_at' => now()]);

        $subject = $review->evaluation->employee?->full_name;

        ActivityLogger::log(
            event: 'submitted',
            description: ($review->isSelf() ? "Submitted a self-review ({$subject})" : "Submitted a review of {$subject}").$channel,
            subject: $review->evaluation,
            logName: 'performance',
            subjectLabel: $subject,
        );

        $this->tellEvaluator(
            $review,
            $review->isSelf() ? 'Self-review handed in' : 'Review handed in',
            $review->isSelf()
                ? "{$subject} handed in their self-review."
                : 'A '.self::label($review->relationship)." review of {$subject} is in.",
        );
    }

    /**
     * Say no to a review request. A self-review can't be declined — it is the
     * person's own say in their appraisal.
     *
     * @throws AppraisalException
     */
    public function decline(AppraisalReview $review, ?string $reason, string $channel = ''): void
    {
        $this->assertAnswerable($review);

        if ($review->isSelf()) {
            throw new AppraisalException('A self-review can’t be declined. Leave it unsubmitted if you have nothing to add.');
        }

        $review->update([
            'status' => 'declined',
            'declined_at' => now(),
            'decline_reason' => $this->text($reason),
        ]);

        $subject = $review->evaluation->employee?->full_name;

        ActivityLogger::log(
            event: 'updated',
            description: "Declined to review {$subject}{$channel}",
            subject: $review->evaluation,
            logName: 'performance',
            subjectLabel: $subject,
        );

        $this->tellEvaluator($review, 'Review declined', "{$review->reviewer?->full_name} won't review {$subject}"
            .($review->decline_reason ? ": “{$review->decline_reason}”" : '.'), 'warning');
    }

    /**
     * Withdraw a pending request (HR).
     *
     * @throws AppraisalException
     */
    public function cancel(AppraisalReview $review, string $channel = ''): void
    {
        if (! $review->isPending()) {
            throw new AppraisalException('Only a review that is still waiting can be cancelled.');
        }

        $review->update(['status' => 'cancelled']);

        ActivityLogger::log(
            event: 'updated',
            description: "Cancelled {$review->reviewer?->full_name}'s review of {$review->evaluation->employee?->full_name}{$channel}",
            subject: $review->evaluation,
            logName: 'performance',
            subjectLabel: $review->evaluation->employee?->full_name,
        );
    }

    /**
     * Nudge the reviewer about a pending request — at most once a day.
     *
     * @throws AppraisalException
     */
    public function remind(AppraisalReview $review, ?User $by): void
    {
        if (! $review->isPending() || ! $review->evaluation->isEditable()) {
            throw new AppraisalException('Only a review that is still waiting can be reminded.');
        }

        if ($review->reminded_at !== null && $review->reminded_at->gt(now()->subHours(self::REMIND_EVERY_HOURS))) {
            throw new AppraisalException("{$review->reviewer?->full_name} was already reminded today.");
        }

        $review->update(['reminded_at' => now()]);

        $this->tellReviewer($review, 'Reminder: a review is waiting', $this->askBody($review, $by), 'warning', $by);
    }

    /**
     * Close every request still waiting when the appraisal is submitted — the
     * work they fed is done. Their reviewers are not told; nothing is owed.
     */
    public function closeOutstanding(PerformanceEvaluation $evaluation): int
    {
        return AppraisalReview::query()
            ->where('performance_evaluation_id', $evaluation->id)
            ->pending()
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * Ask, at cycle launch, for the appraised person's self-review and/or their
     * manager's review. Anyone who can't be asked is quietly left out — a launch
     * over hundreds of people does not stop on one missing account.
     */
    public function requestAtLaunch(PerformanceEvaluation $evaluation, bool $self, bool $manager, ?User $by): int
    {
        $evaluation->loadMissing('employee.manager', 'period');
        $subject = $evaluation->employee;

        if ($subject === null) {
            return 0;
        }

        $due = $this->dueDate($evaluation, null);
        $count = 0;

        foreach (array_filter([$self ? $subject : null, $manager ? $subject->manager : null]) as $reviewer) {
            if ($this->refusal($evaluation, $reviewer) === null) {
                $this->ask($evaluation, $reviewer, $subject, $by, $due);
                $count++;
            }
        }

        return $count;
    }

    /**
     * The account a reviewer answers with: active, a member of this workspace,
     * and allowed to write reviews.
     */
    public function answerer(Employee $reviewer): ?User
    {
        $user = $reviewer->user;

        if ($user === null || ! $user->is_active) {
            return null;
        }

        $organization = app(Tenancy::class)->id();

        if ($organization !== null && ! $user->isMemberOf($organization)) {
            return null;
        }

        return $user->hasPermissionTo('performance.participate') ? $user : null;
    }

    /**
     * The relationship in words — "peer", "direct report".
     */
    public static function label(string $relationship): string
    {
        return match ($relationship) {
            'self' => 'self',
            'manager' => 'manager',
            'direct_report' => 'direct report',
            default => 'peer',
        };
    }

    /**
     * Create (or re-open a declined or cancelled) request and tell the reviewer.
     */
    private function ask(PerformanceEvaluation $evaluation, Employee $reviewer, Employee $subject, ?User $by, CarbonInterface $due): AppraisalReview
    {
        $review = AppraisalReview::updateOrCreate(
            ['performance_evaluation_id' => $evaluation->id, 'reviewer_id' => $reviewer->id],
            [
                'relationship' => self::relationshipOf($reviewer, $subject),
                'status' => 'pending',
                'requested_by' => $by?->id,
                'due_on' => $due->toDateString(),
                'submitted_at' => null,
                'declined_at' => null,
                'decline_reason' => null,
                'reminded_at' => null,
            ],
        );

        $review->setRelation('evaluation', $evaluation);
        $review->setRelation('reviewer', $reviewer);

        $this->tellReviewer(
            $review,
            $review->isSelf() ? 'Your self-review is open' : 'You’ve been asked for a review',
            $this->askBody($review, $by),
            'info',
            $by,
        );

        return $review;
    }

    /**
     * When a review falls due: the date asked for, else the cycle's end while it
     * is still ahead, else a fortnight from today.
     */
    private function dueDate(PerformanceEvaluation $evaluation, ?string $dueOn): CarbonInterface
    {
        if ($dueOn !== null && $dueOn !== '') {
            return Carbon::parse($dueOn)->startOfDay();
        }

        $end = $evaluation->period?->end_date;

        return $end !== null && $end->gte(today()) ? $end : today()->addDays(14);
    }

    private function askBody(AppraisalReview $review, ?User $by): string
    {
        $subject = $review->evaluation->employee?->full_name;
        $cycle = $review->evaluation->period?->name ?? 'this cycle';
        $due = $review->due_on ? ' It’s due '.$review->due_on->format('M j').'.' : '';

        return $review->isSelf()
            ? "Say how your {$cycle} went, criterion by criterion. Your evaluator reads it beside their own ratings.{$due}"
            : ($by?->full_name ?? 'HR').' asked for your view of '.$subject.' as their '.self::label($review->relationship)." ({$cycle}).{$due}";
    }

    /**
     * @throws AppraisalException
     */
    private function assertAnswerable(AppraisalReview $review): void
    {
        if (! $review->isPending()) {
            throw new AppraisalException(match ($review->status) {
                'submitted' => 'This review has already been handed in.',
                'declined' => 'This review was declined.',
                default => 'This review was withdrawn.',
            });
        }

        if (! $review->evaluation->isEditable()) {
            throw new AppraisalException('The appraisal has been submitted, so reviews are closed.');
        }
    }

    private function tellReviewer(AppraisalReview $review, string $title, string $body, string $level, ?User $actor): void
    {
        $user = $review->reviewer?->user;

        if ($user !== null && $user->is_active) {
            Notifier::toUser($user, $title, $body, '/performance/reviews/'.$review->hashid, $level, 'performance', $actor);
        }
    }

    private function tellEvaluator(AppraisalReview $review, string $title, string $body, string $level = 'info'): void
    {
        $evaluator = $review->evaluation->evaluator;

        if ($evaluator !== null && $evaluator->is_active) {
            Notifier::toUser($evaluator, $title, $body, '/performance/'.$review->evaluation->hashid, $level, 'performance');
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

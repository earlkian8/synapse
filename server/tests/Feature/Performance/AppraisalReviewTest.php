<?php

use App\Models\AppraisalReview;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\AppraisalWorkflow;
use App\Support\Performance\FeedbackSummary;
use App\Support\Performance\ReviewWorkflow;
use Illuminate\Support\Facades\Notification;

/*
| Self, manager, peer and direct-report reviews (ADR 0072): HR asks the people
| around an appraisal, each answers on the appraisal's own scales, and the
| evaluator reads them pooled — never the result itself.
*/

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    [$this->subjectUser, $this->subject] = perfParticipant();
    $this->evaluation = perfAppraisal($this->subject, $this->hr);
    $this->reviews = app(ReviewWorkflow::class);
});

/**
 * The appraisal's lines, in reading order: goal attainment (%), teamwork (1–5).
 *
 * @return array{0: int, 1: int}
 */
function perfReviewLines(PerformanceEvaluation $evaluation): array
{
    return $evaluation->scores()->orderBy('sort_order')->pluck('id')->all();
}

test('the relationship comes from the reporting line, never from what was typed', function () {
    [, $manager] = perfParticipant();
    [, $report] = perfParticipant();
    [, $peer] = perfParticipant();
    $this->subject->update(['manager_id' => $manager->id]);
    $report->update(['manager_id' => $this->subject->id]);

    $subject = $this->subject->refresh();

    expect(ReviewWorkflow::relationshipOf($subject, $subject))->toBe('self')
        ->and(ReviewWorkflow::relationshipOf($manager, $subject))->toBe('manager')
        ->and(ReviewWorkflow::relationshipOf($report->refresh(), $subject))->toBe('direct_report')
        ->and(ReviewWorkflow::relationshipOf($peer, $subject))->toBe('peer');
});

test('asking for reviews tells each reviewer where to answer', function () {
    [$peerUser, $peer] = perfParticipant();

    $outcome = $this->reviews->request($this->evaluation, [$this->subject, $peer], $this->hr);

    expect($outcome['requested'])->toHaveCount(2)->and($outcome['refused'])->toBe([]);

    $self = AppraisalReview::where('reviewer_id', $this->subject->id)->firstOrFail();
    $peerReview = AppraisalReview::where('reviewer_id', $peer->id)->firstOrFail();

    expect($self->relationship)->toBe('self')
        ->and($peerReview->relationship)->toBe('peer')
        ->and($peerReview->due_on->toDateString())->toBe($this->evaluation->period->end_date->toDateString());

    Notification::assertSentTo($peerUser, SystemNotification::class, fn (SystemNotification $n): bool => $n->url === '/performance/reviews/'.$peerReview->hashid
        && str_contains($n->body, $this->subject->full_name));
    Notification::assertSentTo($this->subjectUser, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Your self-review is open');
});

test('each person is checked on their own, and the rest are still asked', function () {
    [, $peer] = perfParticipant();
    $noAccount = Employee::factory()->create(['employment_status' => 'active', 'user_id' => null]);
    $gone = Employee::factory()->create(['employment_status' => 'resigned']);
    $evaluatorEmployee = Employee::factory()->create(['employment_status' => 'active', 'user_id' => $this->hr->id]);

    $outcome = $this->reviews->request($this->evaluation, [$peer, $noAccount, $gone, $evaluatorEmployee], $this->hr);

    expect($outcome['requested'])->toHaveCount(1)
        ->and($outcome['refused'][$noAccount->full_name])->toContain('has no account')
        ->and($outcome['refused'][$gone->full_name])->toContain('not an active employee')
        ->and($outcome['refused'][$evaluatorEmployee->full_name])->toContain('conducting this appraisal');

    $again = $this->reviews->request($this->evaluation, [$peer], $this->hr);

    expect($again['refused'][$peer->full_name])->toBe("{$peer->full_name} has already been asked.");
});

test('someone without the self-service permission cannot be asked', function () {
    $role = makeRole('no-perf', []);
    $user = User::factory()->create(['is_active' => true]);
    $user->roles()->attach($role);
    $colleague = Employee::factory()->create(['employment_status' => 'active', 'user_id' => $user->id]);

    $outcome = $this->reviews->request($this->evaluation, [$colleague], $this->hr);

    expect($outcome['requested'])->toBe([])
        ->and($outcome['refused'][$colleague->full_name])->toContain('has no account that can write reviews');
});

test('a reviewer’s rating is checked against the line’s own scale', function () {
    [, $peer] = perfParticipant();
    $review = $this->reviews->request($this->evaluation, [$peer], $this->hr)['requested'][0];
    [$goal, $teamwork] = perfReviewLines($this->evaluation);

    expect(fn () => $this->reviews->answer($review, [$teamwork => ['score' => 9]], null, null))
        ->toThrow(AppraisalException::class, 'outside its scale');

    $this->reviews->answer($review, [$goal => ['score' => 70, 'remarks' => 'Hit most targets.'], $teamwork => ['score' => 4]], 'Calm under pressure.', null);

    expect($review->scores()->count())->toBe(2)
        ->and($review->refresh()->strengths)->toBe('Calm under pressure.');
});

test('a self-review rates everything; anyone else rates something or writes something', function () {
    [, $peer] = perfParticipant();
    ['requested' => [$self, $peerReview]] = $this->reviews->request($this->evaluation, [$this->subject, $peer], $this->hr);
    [$goal, $teamwork] = perfReviewLines($this->evaluation);

    $this->reviews->answer($self, [$goal => ['score' => 90]], null, null);

    expect(fn () => $this->reviews->submit($self->refresh()))
        ->toThrow(AppraisalException::class, 'Rate every criterion before handing in your self-review.')
        ->and(fn () => $this->reviews->submit($peerReview))
        ->toThrow(AppraisalException::class, 'Rate at least one criterion or write an answer');

    $this->reviews->answer($peerReview, [], null, 'Could share context earlier.');
    $this->reviews->submit($peerReview->refresh());

    expect($peerReview->refresh()->status)->toBe('submitted');

    Notification::assertSentTo($this->hr, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Review handed in');
});

test('a self-review cannot be declined, any other review can — with the evaluator told', function () {
    [, $peer] = perfParticipant();
    ['requested' => [$self, $peerReview]] = $this->reviews->request($this->evaluation, [$this->subject, $peer], $this->hr);

    expect(fn () => $this->reviews->decline($self, 'No'))->toThrow(AppraisalException::class, 'can’t be declined');

    $this->reviews->decline($peerReview, 'We have not worked together this cycle.');

    expect($peerReview->refresh()->status)->toBe('declined');
    Notification::assertSentTo($this->hr, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Review declined'
        && str_contains($n->body, 'not worked together'));
});

test('submitting the appraisal closes the reviews still waiting and keeps the answered ones', function () {
    [, $peer] = perfParticipant();
    [, $other] = perfParticipant();
    ['requested' => [$answered, $waiting]] = $this->reviews->request($this->evaluation, [$peer, $other], $this->hr);
    [$goal] = perfReviewLines($this->evaluation);

    $this->reviews->answer($answered, [$goal => ['score' => 60]], null, null);
    $this->reviews->submit($answered->refresh());

    perfSubmit($this->evaluation);

    expect($answered->refresh()->status)->toBe('submitted')
        ->and($waiting->refresh()->status)->toBe('cancelled')
        ->and(fn () => $this->reviews->answer($waiting->refresh(), [], 'Late', null))->toThrow(AppraisalException::class, 'withdrawn')
        ->and(fn () => $this->reviews->request($this->evaluation, [$peer], $this->hr))
        ->toThrow(AppraisalException::class, 'only be asked for while the appraisal is in progress');
});

test('peers are pooled and shown only once two have answered; self is attributed', function () {
    [, $peerA] = perfParticipant();
    [, $peerB] = perfParticipant();
    ['requested' => [$self, $a, $b]] = $this->reviews->request($this->evaluation, [$this->subject, $peerA, $peerB], $this->hr);
    [$goal, $teamwork] = perfReviewLines($this->evaluation);

    foreach ([[$self, 90, 5], [$a, 60, 3]] as [$review, $g, $t]) {
        $this->reviews->answer($review, [$goal => ['score' => $g], $teamwork => ['score' => $t]], 'Strong quarter.', null);
        $this->reviews->submit($review->refresh());
    }

    $summary = app(FeedbackSummary::class)->for($this->evaluation);
    $peers = collect($summary['columns'])->firstWhere('key', 'peer');

    expect($peers['answered'])->toBe(1)->and($peers['shown'])->toBeFalse()
        ->and($summary['lines'][0]['values'])->not->toHaveKey('peer')
        ->and($summary['lines'][0]['values']['self']['formatted'])->toBe('90%')
        ->and(collect($summary['comments'])->firstWhere('relationship', 'self')['by'])->toBe($this->subject->full_name);

    $this->reviews->answer($b, [$goal => ['score' => 80], $teamwork => ['score' => 4]], 'Strong quarter.', null);
    $this->reviews->submit($b->refresh());

    $summary = app(FeedbackSummary::class)->for($this->evaluation);

    expect(collect($summary['columns'])->firstWhere('key', 'peer')['shown'])->toBeTrue()
        ->and($summary['lines'][0]['values']['peer']['score'])->toBe(70.0)
        ->and($summary['lines'][0]['values']['peer']['count'])->toBe(2)
        ->and($summary['lines'][1]['values']['peer']['formatted'])->toBe('3.5')
        ->and(collect($summary['comments'])->where('relationship', 'peer')->pluck('by')->unique()->all())->toBe([null]);
});

test('HR asks for reviews from the scorecard, and cancels or reminds a waiting one', function () {
    [$peerUser, $peer] = perfParticipant();

    $this->post(route('performance.reviews.store', $this->evaluation), ['reviewer_ids' => [$peer->id]])
        ->assertRedirect();
    assertToast('success', 'Asked 1 person');

    $review = AppraisalReview::firstOrFail();

    $this->post(route('performance.reviews.remind', $review))->assertRedirect();
    assertToast('success', 'Reminder sent');
    $this->post(route('performance.reviews.remind', $review))->assertRedirect();
    assertToast('warning', 'already reminded today');

    $this->post(route('performance.reviews.cancel', $review))->assertRedirect();
    expect($review->refresh()->status)->toBe('cancelled');

    Notification::assertSentToTimes($peerUser, SystemNotification::class, 2);
});

test('asking for reviews needs performance.manage', function () {
    [, $peer] = perfParticipant();
    actingAsUserWith(['performance.view']);

    $this->post(route('performance.reviews.store', $this->evaluation), ['reviewer_ids' => [$peer->id]])
        ->assertForbidden();
});

test('a reviewer saves and submits through their own form, and nobody else can reach it', function () {
    [$peerUser, $peer] = perfParticipant();
    $review = $this->reviews->request($this->evaluation, [$peer], $this->hr)['requested'][0];
    [$goal, $teamwork] = perfReviewLines($this->evaluation);

    $this->actingAs($peerUser)
        ->post(route('performance.reviews.submit', $review), [
            'scores' => [['id' => $goal, 'score' => 75, 'remarks' => null], ['id' => $teamwork, 'score' => null, 'remarks' => null]],
            'strengths' => 'Unblocks the team.',
            'improvements' => null,
        ])
        ->assertRedirect();
    assertToast('success', 'Review handed in');

    expect($review->refresh()->status)->toBe('submitted');

    [$stranger] = perfParticipant();
    $this->actingAs($stranger)->patch(route('performance.reviews.update', $review), ['scores' => []])->assertNotFound();
    $this->actingAs($stranger)->post(route('performance.reviews.decline', $review))->assertNotFound();
});

test('a launch can ask for self-reviews and the managers’ reviews', function () {
    $period = EvaluationPeriod::factory()->create(['end_date' => now()->addMonth()]);
    Employee::query()->whereKeyNot($this->subject->id)->update(['employment_status' => 'resigned']);
    [, $manager] = perfParticipant();
    $this->subject->update(['manager_id' => $manager->id]);
    $template = $this->evaluation->template;
    $template->update(['is_default' => true]);

    $launch = app(AppraisalWorkflow::class)->launch($period, null, $template, $this->hr, selfReviews: true, managerReviews: true);

    $evaluation = PerformanceEvaluation::where('evaluation_period_id', $period->id)->where('employee_id', $this->subject->id)->firstOrFail();

    expect($launch->reviews)->toBe(3)
        ->and($evaluation->reviews()->pluck('relationship')->sort()->values()->all())->toBe(['manager', 'self'])
        ->and($launch->message()[0])->toContain('Asked for 3 reviews.');
});

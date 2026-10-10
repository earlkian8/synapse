<?php

use App\Models\AppraisalReview;
use App\Models\GoalTemplate;
use App\Support\Performance\AppraisalWorkflow;
use App\Support\Performance\CalibrationWorkflow;
use App\Support\Performance\GoalWorkflow;
use App\Support\Performance\ReviewWorkflow;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The screens of performance taking part (ADRs 0072, 0073): what each page is
| given, and that nobody is given what they should not see.
*/

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    [$this->employeeUser, $this->employee] = perfParticipant();
    $this->evaluation = perfAppraisal($this->employee, $this->hr);
});

test('the scorecard carries the reviews, the goals, calibration and who may ask', function () {
    [, $peer] = perfParticipant();
    app(ReviewWorkflow::class)->request($this->evaluation, [$this->employee, $peer], $this->hr);
    app(GoalWorkflow::class)->set([$this->employee], $this->evaluation->period, ['title' => 'Ship it'], null, $this->hr);

    $this->get(route('performance.show', $this->evaluation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/show')
            ->where('feedback.counts.asked', 2)
            ->has('feedback.requests', 2)
            ->has('goals.items', 1)
            ->where('calibration.holding', null)
            ->where('can.own', false)
            ->where('reviewers', fn ($reviewers) => collect($reviewers)->firstWhere('id', $peer->id)['asked'] === 'pending'
                && collect($reviewers)->firstWhere('id', $this->employee->id)['relationship'] === 'self'));
});

test('the reviewer’s form never shows the evaluator’s ratings', function () {
    [$peerUser, $peer] = perfParticipant();
    $line = $this->evaluation->scores()->orderBy('sort_order')->first();
    app(AppraisalWorkflow::class)->rate($this->evaluation, [$line->id => ['score' => 95, 'remarks' => 'Private evidence']]);
    $review = app(ReviewWorkflow::class)->request($this->evaluation, [$peer], $this->hr)['requested'][0];

    $this->actingAs($peerUser)
        ->get(route('performance.reviews.show', $review))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/review')
            ->where('review.relationship', 'peer')
            ->where('criteria.0.score', null)
            ->where('criteria.0.remarks', null)
            ->where('review.subject.full_name', $this->employee->full_name));

    $this->actingAs($this->employeeUser)->get(route('performance.reviews.show', $review))->assertNotFound();
});

test('Reviews lists what waits, and the section switcher counts it', function () {
    [$peerUser, $peer] = perfParticipant();
    app(ReviewWorkflow::class)->request($this->evaluation, [$peer], $this->hr);

    $this->actingAs($peerUser)
        ->get(route('performance.reviews.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/reviews')
            ->has('reviews', 1)
            ->where('nav.reviews', 1));
});

test('My appraisals hides a result until it is shared, then shows it with the self-review', function () {
    $self = app(ReviewWorkflow::class)->request($this->evaluation, [$this->employee], $this->hr)['requested'][0];

    $this->actingAs($this->employeeUser)
        ->get(route('performance.me'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/me')
            ->where('appraisals.0.shared', false)
            ->where('appraisals.0.result_label', null)
            ->where('appraisals.0.self_review.open', true));

    $lines = $this->evaluation->scores()->orderBy('sort_order')->pluck('id');
    $reviews = app(ReviewWorkflow::class);
    $reviews->answer($self, [$lines[0] => ['score' => 90], $lines[1] => ['score' => 5]], 'Shipped the portal.', null);
    $reviews->submit($self->refresh());
    perfSubmit($this->evaluation);

    $this->actingAs($this->employeeUser)
        ->get(route('performance.me.show', $this->evaluation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/my-appraisal')
            ->where('evaluation.result_label', 'Exceeds Expectations')
            ->missing('evaluation.ai_insights')
            ->where('selfReview.strengths', 'Shipped the portal.')
            ->where('selfReview.lines.'.$lines[0].'.formatted', '90%'));
});

test('Goals and My goals render the cycle’s goals with their check-ins', function () {
    [$goal] = app(GoalWorkflow::class)->set([$this->employee], $this->evaluation->period, ['title' => 'Ship it'], null, $this->hr);
    app(GoalWorkflow::class)->checkIn($goal, 40, 'at_risk', 'Behind on QA.', $this->employeeUser);

    $this->get(route('performance.goals.index', ['period' => $this->evaluation->evaluation_period_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/goals')
            ->has('goals', 1)
            ->where('goals.0.check_ins.0.note', 'Behind on QA.')
            ->where('stats.at_risk', 1));

    $this->actingAs($this->employeeUser)
        ->get(route('performance.me.goals', ['goal' => $goal->hashid]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/my-goals')
            ->where('focus', $goal->hashid)
            ->where('goals.0.check_ins.0.by_owner', true)
            ->where('attainment', 40));
});

test('a calibration session’s room shows each appraisal scored and rated now', function () {
    $session = app(CalibrationWorkflow::class)->open($this->evaluation->period, 'Mid-year', null, null, null, [], $this->hr);
    perfSubmit($this->evaluation);
    app(CalibrationWorkflow::class)->adjust($session, $this->evaluation, 'meets', 'Consistency with peers.', $this->hr);

    $this->get(route('performance.calibration.show', $session))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('performance/calibration-session')
            ->where('session.scope_label', 'Everyone in the cycle')
            ->where('board.rows.0.scored.key', 'exceeds')
            ->where('board.rows.0.current.key', 'meets')
            ->where('board.counts.held', 1));

    $this->get(route('performance.show', $this->evaluation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('evaluation.scored_label', 'Exceeds Expectations')
            ->where('calibration.holding.name', 'Mid-year')
            ->has('calibration.adjustments', 1));
});

test('HR viewing their own appraisal sees it as theirs, with nobody to ask', function () {
    [$hrUser, $hrEmployee] = perfParticipant(['performance.view', 'performance.manage']);
    $own = perfAppraisal($hrEmployee, $this->hr);

    $this->actingAs($hrUser)
        ->get(route('performance.show', $own))
        ->assertInertia(fn (Assert $page) => $page->where('can.own', true)->where('reviewers', []));
});

test('the goal library is managed under the performance framework', function () {
    $this->post(route('setup.kpi.goals.store'), [
        'name' => 'Reduce ticket backlog',
        'measure' => 'number',
        'start_value' => 120,
        'target_value' => 20,
        'unit' => 'tickets',
    ])->assertRedirect();

    $template = GoalTemplate::firstOrFail();

    expect((float) $template->target_value)->toBe(20.0);

    $this->post(route('setup.kpi.goals.store'), ['name' => 'Flat', 'measure' => 'number', 'start_value' => 5, 'target_value' => 5])
        ->assertSessionHasErrors('target_value');

    $this->delete(route('setup.kpi.goals.destroy', $template))->assertRedirect();
    expect(GoalTemplate::count())->toBe(0);

    $this->patch(route('setup.kpi.goals.restore', $template->hashid))->assertRedirect();

    $this->get(route('setup.kpi.index'))
        ->assertInertia(fn (Assert $page) => $page->has('goalTemplates', 1)->where('goalTemplates.0.target_label', '120 tickets → 20 tickets'));

    actingAsUserWith(['setup.kpi.view']);
    $this->post(route('setup.kpi.goals.store'), ['name' => 'X', 'measure' => 'percent'])->assertForbidden();
});

test('a review left waiting when the appraisal is submitted drops off the list', function () {
    [$peerUser, $peer] = perfParticipant();
    app(ReviewWorkflow::class)->request($this->evaluation, [$peer], $this->hr);
    perfSubmit($this->evaluation);

    expect(AppraisalReview::firstOrFail()->status)->toBe('cancelled');

    $this->actingAs($peerUser)
        ->get(route('performance.reviews.index'))
        ->assertInertia(fn (Assert $page) => $page->has('reviews', 0)->where('nav.reviews', 0));
});

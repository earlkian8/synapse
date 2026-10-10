<?php

use App\Models\CalibrationAdjustment;
use App\Models\CalibrationSession;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PerformanceEvaluation;
use App\Notifications\SystemNotification;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\CalibrationBoard;
use App\Support\Performance\CalibrationWorkflow;
use App\Support\Performance\PerformanceCalibration;
use Illuminate\Support\Facades\Notification;

/*
| Calibration sessions (ADR 0073): a session covers a cycle or some of its
| departments, never overlapping another open one; it moves submitted ratings to
| another band of their own model with a reason, holds results back while open,
| and shares them when it ends.
*/

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->ops = Department::factory()->create(['name' => 'Operations']);
    [$this->aliceUser, $this->alice] = perfParticipant([], ['department_id' => $this->sales->id]);
    [, $this->bob] = perfParticipant([], ['department_id' => $this->ops->id]);
    $this->evaluation = perfAppraisal($this->alice, $this->hr);
    $this->period = $this->evaluation->period;
    $this->calibration = app(CalibrationWorkflow::class);
});

test('two open sessions never cover the same people in a cycle', function () {
    $this->calibration->open($this->period, 'Sales', [$this->sales->id], null, null, [], $this->hr);

    expect(fn () => $this->calibration->open($this->period, 'Everyone', null, null, null, [], $this->hr))
        ->toThrow(AppraisalException::class, '“Sales” is already calibrating')
        ->and($this->calibration->open($this->period, 'Ops', [$this->ops->id], null, null, [], $this->hr)->name)->toBe('Ops');
});

test('participants are told, with a link to the session', function () {
    [$calibrator] = perfParticipant(['performance.view']);

    $session = $this->calibration->open($this->period, 'Mid-year', null, now()->addWeek()->toDateString(), null, [$calibrator], $this->hr);

    Notification::assertSentTo($calibrator, SystemNotification::class, fn (SystemNotification $n): bool => $n->url === '/performance/calibration/'.$session->hashid);
});

test('a move changes the official rating, keeps what was scored, and back again clears it', function () {
    $session = $this->calibration->open($this->period, 'Mid-year', null, null, null, [], $this->hr);
    perfSubmit($this->evaluation, goal: 80, teamwork: 4);

    $scored = $this->evaluation->result_band;
    expect($scored)->toBe('exceeds');

    $this->calibration->adjust($session, $this->evaluation, 'meets', 'Rated against a softer bar than the rest of Sales.', $this->hr);
    $evaluation = $this->evaluation->refresh();

    expect($evaluation->result_band)->toBe('meets')
        ->and($evaluation->result_label)->toBe('Meets Expectations')
        ->and($evaluation->scored_band)->toBe('exceeds')
        ->and($evaluation->isCalibrated())->toBeTrue()
        ->and((float) $evaluation->overall_percent)->toBe(78.0);

    $this->calibration->adjust($session, $evaluation, 'exceeds', 'On reflection the original stands.', $this->hr);
    $evaluation->refresh();

    expect($evaluation->result_band)->toBe('exceeds')
        ->and($evaluation->scored_band)->toBeNull()
        ->and($evaluation->calibrated_at)->toBeNull()
        ->and(CalibrationAdjustment::count())->toBe(2);
});

test('only a submitted, unacknowledged appraisal in the session, to a band of its own model, with a reason', function () {
    $session = $this->calibration->open($this->period, 'Ops only', [$this->ops->id], null, null, [], $this->hr);

    expect(fn () => $this->calibration->adjust($session, $this->evaluation, 'meets', 'Long enough reason', $this->hr))
        ->toThrow(AppraisalException::class, 'isn’t part of this session');

    $everyone = $this->calibration->open(
        $this->period, 'Sales', [$this->sales->id], null, null, [], $this->hr,
    );

    expect(fn () => $this->calibration->adjust($everyone, $this->evaluation, 'meets', 'Long enough reason', $this->hr))
        ->toThrow(AppraisalException::class, 'still in progress');

    perfSubmit($this->evaluation);

    expect(fn () => $this->calibration->adjust($everyone, $this->evaluation, 'platinum', 'Long enough reason', $this->hr))
        ->toThrow(AppraisalException::class, 'isn’t part of this appraisal’s rating model')
        ->and(fn () => $this->calibration->adjust($everyone, $this->evaluation, 'meets', 'no', $this->hr))
        ->toThrow(AppraisalException::class, 'Say why the rating moves')
        ->and(fn () => $this->calibration->adjust($everyone, $this->evaluation, 'exceeds', 'Long enough reason', $this->hr))
        ->toThrow(AppraisalException::class, 'already rated');
});

test('nobody calibrates their own rating', function () {
    $session = $this->calibration->open($this->period, 'Mid-year', null, null, null, [], $this->hr);
    perfSubmit($this->evaluation);

    expect(fn () => $this->calibration->adjust($session, $this->evaluation, 'meets', 'I deserve less.', $this->aliceUser))
        ->toThrow(AppraisalException::class, 'your own rating');
});

test('a move on a result the employee can already read tells them', function () {
    perfSubmit($this->evaluation);
    $session = $this->calibration->open($this->period, 'Late session', null, null, null, [], $this->hr);

    $this->calibration->adjust($session, $this->evaluation, 'outstanding', 'Carried the regional launch alone.', $this->hr);

    Notification::assertSentTo($this->aliceUser, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Your appraisal rating was updated'
        && str_contains($n->body, 'Outstanding'));
});

test('a session can be cancelled only before anything moved; completing it locks the moves', function () {
    $session = $this->calibration->open($this->period, 'Mid-year', null, null, null, [], $this->hr);
    perfSubmit($this->evaluation);
    $this->calibration->adjust($session, $this->evaluation, 'meets', 'Consistency with peers.', $this->hr);

    expect(fn () => $this->calibration->cancel($session))->toThrow(AppraisalException::class, 'Complete it instead');

    $this->calibration->complete($session);

    expect($session->refresh()->status)->toBe('completed')
        ->and(fn () => $this->calibration->adjust($session, $this->evaluation->refresh(), 'exceeds', 'Second thoughts.', $this->hr))
        ->toThrow(AppraisalException::class, 'This session is completed');
});

test('the band spread reads before and after the session’s moves', function () {
    $other = perfAppraisal($this->bob, $this->hr, $this->period);
    $session = $this->calibration->open($this->period, 'Mid-year', null, null, null, [], $this->hr);
    perfSubmit($this->evaluation, goal: 80, teamwork: 4);
    perfSubmit($other, goal: 80, teamwork: 4);

    $this->calibration->adjust($session, $this->evaluation, 'meets', 'Consistency with peers.', $this->hr);

    $board = app(CalibrationBoard::class)->for($session);
    $spread = collect($board['spread'])->keyBy('label');

    expect($spread['Exceeds Expectations']['before'])->toBe(2)
        ->and($spread['Exceeds Expectations']['after'])->toBe(1)
        ->and($spread['Meets Expectations']['after'])->toBe(1)
        ->and($board['counts']['moved'])->toBe(1)
        ->and($board['counts']['held'])->toBe(2);

    // The cycle's band distribution reads the calibrated rating.
    $distribution = collect(app(PerformanceCalibration::class)->distribution(
        PerformanceEvaluation::query()->forPeriod($this->period->id)->get(),
    ))->keyBy('label');

    expect($distribution['Meets Expectations']['count'])->toBe(1);
});

test('the screens: open a session, move a rating, complete it', function () {
    perfSubmit($this->evaluation);

    $this->post(route('performance.calibration.store'), [
        'name' => 'Sales calibration',
        'evaluation_period_id' => $this->period->id,
        'department_ids' => [$this->sales->id],
    ])->assertRedirect();

    $session = CalibrationSession::firstOrFail();

    $this->post(route('performance.calibration.adjust', $session), [
        'evaluation_id' => $this->evaluation->id,
        'band' => 'meets',
        'reason' => 'In line with the rest of Sales.',
    ])->assertRedirect();
    assertToast('success', 'Moved to “Meets Expectations”.');

    $this->post(route('performance.calibration.complete', $session))->assertRedirect();
    assertToast('success', 'Session completed.');

    actingAsUserWith(['performance.view']);
    $this->post(route('performance.calibration.store'), ['name' => 'X', 'evaluation_period_id' => $this->period->id])->assertForbidden();
});

test('a session’s participants must be able to see appraisals', function () {
    [$staff] = perfParticipant();

    $this->post(route('performance.calibration.store'), [
        'name' => 'Mid-year',
        'evaluation_period_id' => $this->period->id,
        'participant_ids' => [$staff->id],
    ])->assertRedirect();

    expect(CalibrationSession::firstOrFail()->participants()->count())->toBe(0);
});

test('an employee moved between departments follows the session’s scope', function () {
    $session = $this->calibration->open($this->period, 'Ops', [$this->ops->id], null, null, [], $this->hr);

    expect($session->evaluations()->count())->toBe(0);

    Employee::whereKey($this->alice->id)->update(['department_id' => $this->ops->id]);

    expect($session->evaluations()->count())->toBe(1);
});

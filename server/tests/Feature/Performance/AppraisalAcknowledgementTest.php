<?php

use App\Models\CalibrationSession;
use App\Models\Employee;
use App\Models\PerformanceEvaluation;
use App\Notifications\SystemNotification;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\AppraisalWorkflow;
use App\Support\Performance\CalibrationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| The employee acknowledges their own appraisal (ADR 0072): it is shared with them
| on submission — unless calibration holds it — they acknowledge it themselves,
| with a comment if they like, and nobody conducts their own appraisal.
*/

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    [$this->employeeUser, $this->employee] = perfParticipant();
    $this->evaluation = perfAppraisal($this->employee, $this->hr);
    $this->workflow = app(AppraisalWorkflow::class);
});

test('submitting shares the appraisal and tells the employee where to read it', function () {
    perfSubmit($this->evaluation);

    expect($this->evaluation->shared_at)->not->toBeNull()
        ->and($this->evaluation->isShared())->toBeTrue();

    Notification::assertSentTo($this->employeeUser, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Your appraisal is ready'
        && $n->url === '/performance/me/'.$this->evaluation->hashid);
});

test('the employee acknowledges it themselves, with a comment, and the evaluator hears', function () {
    perfSubmit($this->evaluation);

    $this->actingAs($this->employeeUser)
        ->post(route('performance.me.acknowledge', $this->evaluation), ['comment' => 'I see teamwork differently; happy to talk.'])
        ->assertRedirect();
    assertToast('success', 'Appraisal acknowledged.');

    $evaluation = $this->evaluation->refresh();

    expect($evaluation->status)->toBe('acknowledged')
        ->and($evaluation->acknowledged_by)->toBe($this->employeeUser->id)
        ->and($evaluation->acknowledgedByEmployee())->toBeTrue()
        ->and($evaluation->employee_comment)->toBe('I see teamwork differently; happy to talk.');

    Notification::assertSentTo($this->hr, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Appraisal acknowledged'
        && str_contains($n->body, 'left a comment'));
});

test('HR can still record a sign-off on the employee’s behalf, and it says so', function () {
    perfSubmit($this->evaluation);

    $this->post(route('performance.acknowledge', $this->evaluation))->assertRedirect();
    assertToast('success', 'Sign-off recorded.');

    $evaluation = $this->evaluation->refresh();

    expect($evaluation->acknowledged_by)->toBe($this->hr->id)
        ->and($evaluation->acknowledgedByEmployee())->toBeFalse();
});

test('an appraisal can be acknowledged once, and only after it is shared', function () {
    expect(fn () => $this->workflow->acknowledge($this->evaluation, by: $this->employeeUser))
        ->toThrow(AppraisalException::class, 'Only a submitted appraisal');

    perfSubmit($this->evaluation);
    $this->workflow->acknowledge($this->evaluation, by: $this->employeeUser);

    expect(fn () => $this->workflow->acknowledge($this->evaluation->refresh(), by: $this->employeeUser))
        ->toThrow(AppraisalException::class, 'already been acknowledged');
});

test('a calibration session holds the result back until it ends', function () {
    $session = app(CalibrationWorkflow::class)->open($this->evaluation->period, 'Q review', null, null, null, [], $this->hr);

    perfSubmit($this->evaluation);

    expect($this->evaluation->shared_at)->toBeNull()
        ->and(fn () => $this->workflow->acknowledge($this->evaluation, by: $this->hr))
        ->toThrow(AppraisalException::class, 'waiting on the calibration session “Q review”');

    Notification::assertNotSentTo($this->employeeUser, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Your appraisal is ready');

    $this->actingAs($this->employeeUser)->post(route('performance.me.acknowledge', $this->evaluation))->assertNotFound();

    $released = app(CalibrationWorkflow::class)->complete($session);

    expect($released)->toBe(1)
        ->and($this->evaluation->refresh()->shared_at)->not->toBeNull();
    Notification::assertSentTo($this->employeeUser, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Your appraisal is ready');
});

test('nobody rates, submits or discards their own appraisal', function () {
    [$hrUser] = perfParticipant(['performance.view', 'performance.manage']);
    $own = perfAppraisal(Employee::where('user_id', $hrUser->id)->firstOrFail(), $this->hr);
    $line = $own->scores()->orderBy('sort_order')->first();

    $this->actingAs($hrUser)
        ->patch(route('performance.update', $own), ['remarks' => null, 'scores' => [['id' => $line->id, 'score' => 80, 'remarks' => null]]])
        ->assertRedirect();
    assertToast('warning', AppraisalWorkflow::OWN_APPRAISAL);

    expect($line->refresh()->score)->toBeNull()
        ->and(fn () => $this->workflow->submit($own, by: $hrUser))->toThrow(AppraisalException::class, 'your own appraisal')
        ->and(fn () => $this->workflow->discard($own, by: $hrUser))->toThrow(AppraisalException::class, 'your own appraisal');
});

test('someone else’s appraisal, or one not yet shared, is not found on My appraisals', function () {
    [$other] = perfParticipant();

    $this->actingAs($this->employeeUser)->get(route('performance.me.show', $this->evaluation))->assertNotFound();

    perfSubmit($this->evaluation);

    $this->actingAs($other)->get(route('performance.me.show', $this->evaluation))->assertNotFound();
    $this->actingAs($other)->post(route('performance.me.acknowledge', $this->evaluation))->assertNotFound();
});

test('taking part needs performance.participate', function () {
    perfSubmit($this->evaluation);
    actingAsUserWith(['performance.view']);

    $this->post(route('performance.me.acknowledge', $this->evaluation))->assertForbidden();
});

test('someone who only takes part lands on their own appraisals', function () {
    $this->actingAs($this->employeeUser)->get(route('performance.index'))->assertRedirect(route('performance.me'));

    actingAsUserWith([]);
    $this->get(route('performance.index'))->assertForbidden();
});

test('the migration back-fills shared_at for appraisals submitted before it', function () {
    perfSubmit($this->evaluation);
    PerformanceEvaluation::query()->whereKey($this->evaluation->id)->update(['shared_at' => null]);

    expect(CalibrationSession::count())->toBe(0);

    // The migration's own statement, run again.
    PerformanceEvaluation::query()
        ->whereIn('status', ['submitted', 'acknowledged'])
        ->update(['shared_at' => DB::raw('coalesce(submitted_at, updated_at)')]);

    expect($this->evaluation->refresh()->shared_at?->toDateTimeString())->toBe($this->evaluation->submitted_at->toDateTimeString());
});

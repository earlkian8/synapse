<?php

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\GoalTemplate;
use App\Models\PerformanceGoal;
use App\Notifications\SystemNotification;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\GoalProgress;
use App\Support\Performance\GoalWorkflow;
use Illuminate\Support\Facades\Notification;

/*
| Goals with check-ins (ADR 0073): set by HR for one person or many, from the
| library or written out; checked in on by their owner or by HR; closed as
| achieved, missed or dropped. A library entry is copied, never linked live.
*/

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    [$this->ownerUser, $this->owner] = perfParticipant();
    $this->period = EvaluationPeriod::factory()->create(['end_date' => now()->addMonths(2)]);
    $this->goals = app(GoalWorkflow::class);
});

test('progress is the distance from start to target, either way round', function () {
    expect(GoalProgress::percent(0, 100, 45))->toBe(45.0)
        ->and(GoalProgress::percent(0, 40, 30))->toBe(75.0)
        ->and(GoalProgress::percent(40, 10, 25))->toBe(50.0)
        ->and(GoalProgress::percent(0, 40, 55))->toBe(100.0)
        ->and(GoalProgress::percent(40, 10, 50))->toBe(0.0)
        ->and(GoalProgress::format(1200000, 'number', 'PHP'))->toBe('1,200,000 PHP')
        ->and(GoalProgress::format(72.5, 'percent', null))->toBe('72.5%');
});

test('HR sets one goal for several people, and each owner hears', function () {
    [$otherUser, $other] = perfParticipant();

    $goals = $this->goals->set([$this->owner, $other], $this->period, [
        'title' => 'Close 40 deals', 'measure' => 'number', 'start_value' => 0, 'target_value' => 40, 'unit' => 'deals',
    ], null, $this->hr);

    expect($goals)->toHaveCount(2)
        ->and($goals[0]->current_value)->toEqual(0)
        ->and($goals[0]->status)->toBe('active');

    Notification::assertSentTo($this->ownerUser, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'New goal: Close 40 deals'
        && $n->url === '/performance/me/goals?goal='.$goals[0]->hashid);
    Notification::assertSentTo($otherUser, SystemNotification::class);
});

test('a goal from the library copies its wording and target, and keeps them', function () {
    $template = GoalTemplate::create(['name' => 'Reduce defects', 'measure' => 'number', 'start_value' => 40, 'target_value' => 10, 'unit' => 'defects']);

    [$goal] = $this->goals->set([$this->owner], $this->period, [], $template, $this->hr);

    $template->update(['name' => 'Renamed', 'target_value' => 5]);

    expect($goal->refresh()->title)->toBe('Reduce defects')
        ->and((float) $goal->target_value)->toBe(10.0)
        ->and((float) $goal->current_value)->toBe(40.0)
        ->and($goal->goal_template_id)->toBe($template->id);
});

test('a percentage goal always runs 0 → 100, and a number goal must go somewhere', function () {
    [$goal] = $this->goals->set([$this->owner], $this->period, ['title' => 'Ship the portal', 'measure' => 'percent', 'start_value' => 30, 'target_value' => 70], null, $this->hr);

    expect((float) $goal->start_value)->toBe(0.0)->and((float) $goal->target_value)->toBe(100.0)
        ->and(fn () => $this->goals->set([$this->owner], $this->period, ['title' => 'Flat', 'measure' => 'number', 'start_value' => 5, 'target_value' => 5], null, $this->hr))
        ->toThrow(AppraisalException::class, 'The target has to differ');
});

test('a check-in moves the goal, keeps its history, and tells the owner when someone else made it', function () {
    [$goal] = $this->goals->set([$this->owner], $this->period, ['title' => 'Close 40 deals', 'measure' => 'number', 'start_value' => 0, 'target_value' => 40, 'unit' => 'deals'], null, $this->hr);

    $this->goals->checkIn($goal, 12, 'at_risk', 'Slow start.', $this->ownerUser);
    $this->goals->checkIn($goal->refresh(), 30, 'on_track', 'Two big wins.', $this->hr);

    $goal->refresh();

    expect((float) $goal->current_value)->toBe(30.0)
        ->and($goal->health)->toBe('on_track')
        ->and($goal->progress())->toBe(75.0)
        ->and($goal->checkIns()->count())->toBe(2)
        ->and($goal->last_check_in_at)->not->toBeNull();

    Notification::assertSentTo($this->ownerUser, SystemNotification::class, fn (SystemNotification $n): bool => $n->title === 'Check-in on your goal');
});

test('check-ins stop when the goal is closed or the cycle is', function () {
    [$goal] = $this->goals->set([$this->owner], $this->period, ['title' => 'Ship it'], null, $this->hr);

    $this->goals->close($goal, 'achieved');

    expect(fn () => $this->goals->checkIn($goal->refresh(), 50, 'on_track', null, $this->ownerUser))
        ->toThrow(AppraisalException::class, 'Only an active goal');

    $this->goals->close($goal->refresh(), 'active');
    $this->period->update(['status' => 'closed']);

    expect(fn () => $this->goals->checkIn($goal->refresh(), 50, 'on_track', null, $this->ownerUser))
        ->toThrow(AppraisalException::class, 'The cycle is closed');
});

test('attainment weighs goals, counts an achieved goal in full and leaves dropped ones out', function () {
    [$a] = $this->goals->set([$this->owner], $this->period, ['title' => 'A', 'weight' => 3], null, $this->hr);
    [$b] = $this->goals->set([$this->owner], $this->period, ['title' => 'B', 'weight' => 1], null, $this->hr);
    [$c] = $this->goals->set([$this->owner], $this->period, ['title' => 'C'], null, $this->hr);

    $this->goals->checkIn($a, 40, 'at_risk', null, $this->hr);
    $this->goals->close($b, 'achieved');
    $this->goals->close($c, 'dropped');

    expect(GoalProgress::attainment(PerformanceGoal::all()))->toBe(55.0);
});

test('a goal with check-ins is dropped, not deleted; an owner deletes only what they added', function () {
    [$set] = $this->goals->set([$this->owner], $this->period, ['title' => 'Set by HR'], null, $this->hr);
    [$own] = $this->goals->set([$this->owner], $this->period, ['title' => 'My own'], null, $this->ownerUser, ownGoal: true);

    expect(fn () => $this->goals->delete($set, $this->ownerUser, asOwner: true))
        ->toThrow(AppraisalException::class, 'Only goals you added yourself');

    $this->goals->checkIn($own, 10, 'on_track', null, $this->ownerUser);

    expect(fn () => $this->goals->delete($own->refresh(), $this->ownerUser, asOwner: true))
        ->toThrow(AppraisalException::class, 'Drop it instead');

    $this->goals->delete($set, $this->hr);

    expect(PerformanceGoal::count())->toBe(1);
});

test('an employee adds their own goal only in an open cycle', function () {
    $draft = EvaluationPeriod::factory()->create(['status' => 'draft']);

    expect(fn () => $this->goals->set([$this->owner], $draft, ['title' => 'Mine'], null, $this->ownerUser, ownGoal: true))
        ->toThrow(AppraisalException::class, 'once the cycle is open');

    // HR can plan ahead in a draft cycle.
    expect($this->goals->set([$this->owner], $draft, ['title' => 'Planned'], null, $this->hr))->toHaveCount(1);
});

test('the owner checks in on their own goal through My goals, and nobody else’s', function () {
    [$goal] = $this->goals->set([$this->owner], $this->period, ['title' => 'Ship it'], null, $this->hr);
    [$stranger] = perfParticipant();

    $this->actingAs($this->ownerUser)
        ->post(route('performance.me.goals.check-in', $goal), ['value' => 60, 'health' => 'on_track', 'note' => 'Beta is out.'])
        ->assertRedirect();
    assertToast('success', 'Checked in.');

    $this->actingAs($stranger)
        ->post(route('performance.me.goals.check-in', $goal), ['value' => 70, 'health' => 'on_track'])
        ->assertNotFound();

    expect((float) $goal->refresh()->current_value)->toBe(60.0);
});

test('HR sets, edits, closes and deletes goals from the Goals screen', function () {
    $this->post(route('performance.goals.store'), [
        'employee_ids' => [$this->owner->id],
        'evaluation_period_id' => $this->period->id,
        'title' => 'Ship the portal',
        'measure' => 'percent',
    ])->assertRedirect();
    assertToast('success', 'Goal set for '.$this->owner->full_name);

    $goal = PerformanceGoal::firstOrFail();

    $this->patch(route('performance.goals.update', $goal), ['title' => 'Ship the client portal', 'measure' => 'percent', 'weight' => 2])
        ->assertRedirect();
    expect($goal->refresh()->title)->toBe('Ship the client portal')->and((float) $goal->weight)->toBe(2.0);

    $this->post(route('performance.goals.status', $goal), ['status' => 'missed'])->assertRedirect();
    expect($goal->refresh()->status)->toBe('missed');

    $this->delete(route('performance.goals.destroy', $goal))->assertRedirect();
    expect(PerformanceGoal::count())->toBe(0);
});

test('setting goals needs performance.manage; checking in on your own needs performance.participate', function () {
    [$goal] = $this->goals->set([$this->owner], $this->period, ['title' => 'Ship it'], null, $this->hr);

    actingAsUserWith(['performance.view']);
    $this->post(route('performance.goals.store'), ['employee_ids' => [$this->owner->id], 'evaluation_period_id' => $this->period->id, 'title' => 'X'])
        ->assertForbidden();
    $this->post(route('performance.me.goals.check-in', $goal), ['value' => 1, 'health' => 'on_track'])->assertForbidden();
});

test('a goal of someone no longer active cannot be set', function () {
    $gone = Employee::factory()->create(['employment_status' => 'resigned']);

    expect(fn () => $this->goals->set([$gone], $this->period, ['title' => 'X'], null, $this->hr))
        ->toThrow(AppraisalException::class, 'not an active employee');
});

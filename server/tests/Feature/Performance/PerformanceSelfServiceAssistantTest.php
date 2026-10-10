<?php

use App\Models\AppraisalReview;
use App\Models\PerformanceGoal;
use App\Models\User;
use App\Services\Assistant\Modules\PerformanceModule;
use App\Services\Assistant\ToolResult;
use App\Support\Performance\GoalWorkflow;
use App\Support\Performance\ReviewWorkflow;
use Illuminate\Support\Facades\Notification;

/*
| The assistant's side of performance taking part (ADRs 0072, 0073): one's own
| appraisals (results only once shared), acknowledging them, the reviews asked
| of one and one's own goals; and HR asking for reviews, setting goals and
| reading the reviews pooled as the scorecard shows them. Gemini is never
| called — these drive the module the way the model would.
*/

function perfAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(PerformanceModule::class)->run($user, $tool, $args);
}

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    [$this->staffUser, $this->staff] = perfParticipant([], ['first_name' => 'Lara', 'last_name' => 'Quinto']);
    $this->evaluation = perfAppraisal($this->staff, $this->hr);
});

test('someone who only takes part is offered their own tools and nothing else', function () {
    $module = app(PerformanceModule::class);

    expect($module->isAvailable($this->staffUser))->toBeTrue()
        ->and(array_column($module->tools($this->staffUser), 'name'))->toEqualCanonicalizing([
            'find_my_appraisals', 'acknowledge_my_appraisal', 'find_my_reviews', 'find_my_goals', 'check_in_goal',
        ])
        ->and($module->requiresConfirmation('acknowledge_my_appraisal'))->toBeTrue()
        ->and($module->requiresConfirmation('request_reviews'))->toBeTrue()
        ->and($module->requiresConfirmation('set_goal'))->toBeTrue()
        ->and($module->isReadOnly('check_in_goal'))->toBeFalse();

    $result = perfAgent($this->staffUser, 'find_appraisals');

    expect($result->failed())->toBeTrue()->and($result->detail)->toContain('permission');
});

test('my appraisals keep a result out of the reply until it is shared', function () {
    $draft = perfAgent($this->staffUser, 'find_my_appraisals');

    expect($draft->cards[0]['meta'])->toContain('Still being rated');

    perfSubmit($this->evaluation);
    $shared = perfAgent($this->staffUser, 'find_my_appraisals');

    expect($shared->cards[0]['badge'])->toBe('Ready to acknowledge')
        ->and($shared->cards[0]['meta'][0])->toContain('Exceeds Expectations');
});

test('the user acknowledges their own appraisal in chat, with a comment', function () {
    perfSubmit($this->evaluation);

    $result = perfAgent($this->staffUser, 'acknowledge_my_appraisal', ['comment' => 'Fair, thank you.']);

    expect($result->failed())->toBeFalse();

    $evaluation = $this->evaluation->refresh();

    expect($evaluation->status)->toBe('acknowledged')
        ->and($evaluation->acknowledged_by)->toBe($this->staffUser->id)
        ->and($evaluation->employee_comment)->toBe('Fair, thank you.');

    expect(perfAgent($this->staffUser, 'acknowledge_my_appraisal')->detail)->toContain('no shared appraisal waiting');
});

test('HR asks for the person, their manager and their reports in one go', function () {
    [, $manager] = perfParticipant([], ['first_name' => 'Mona', 'last_name' => 'Reyes']);
    [, $report] = perfParticipant([], ['first_name' => 'Rico', 'last_name' => 'Tan']);
    $this->staff->update(['manager_id' => $manager->id]);
    $report->update(['manager_id' => $this->staff->id]);

    $result = perfAgent($this->hr, 'request_reviews', [
        'employee' => 'Lara Quinto', 'self' => true, 'manager' => true, 'direct_reports' => true,
    ]);

    expect($result->failed())->toBeFalse()
        ->and(AppraisalReview::pluck('relationship')->sort()->values()->all())->toBe(['direct_report', 'manager', 'self']);
});

test('nobody asks for reviews of their own appraisal in chat', function () {
    [$hrUser, $hrEmployee] = perfParticipant(['performance.view', 'performance.manage'], ['first_name' => 'Hana', 'last_name' => 'Uy']);
    perfAppraisal($hrEmployee, $this->hr);

    $result = perfAgent($hrUser, 'request_reviews', ['employee' => 'Hana Uy', 'self' => true]);

    expect($result->failed())->toBeTrue()->and($result->detail)->toContain('your own appraisal');
});

test('get_appraisal reads the reviews pooled, as the scorecard does', function () {
    [, $peerA] = perfParticipant();
    [, $peerB] = perfParticipant();
    $reviews = app(ReviewWorkflow::class);
    ['requested' => $asked] = $reviews->request($this->evaluation, [$peerA, $peerB], $this->hr);
    $line = $this->evaluation->scores()->orderBy('sort_order')->first();

    $reviews->answer($asked[0], [$line->id => ['score' => 60]], 'Reliable.', null);
    $reviews->submit($asked[0]->refresh());

    $meta = perfAgent($this->hr, 'get_appraisal', ['employee' => 'Lara Quinto'])->cards[0]['meta'];

    expect(collect($meta)->first(fn ($m) => str_starts_with($m, 'Reviews:')))->toContain('Peers 1/2 — not shown until 2 answer')
        ->and(collect($meta)->contains(fn ($m) => str_contains($m, 'Reliable')))->toBeFalse();

    $reviews->answer($asked[1], [$line->id => ['score' => 80]], null, null);
    $reviews->submit($asked[1]->refresh());

    $meta = perfAgent($this->hr, 'get_appraisal', ['employee' => 'Lara Quinto'])->cards[0]['meta'];

    expect(collect($meta)->contains(fn ($m) => str_contains($m, 'peers 70%')))->toBeTrue()
        ->and(collect($meta)->contains(fn ($m) => str_contains($m, 'A peer wrote: Reliable.')))->toBeTrue();
});

test('HR sets a goal for two people; each checks in on their own', function () {
    [$otherUser, $other] = perfParticipant([], ['first_name' => 'Nico', 'last_name' => 'Lim']);

    $result = perfAgent($this->hr, 'set_goal', [
        'employees' => ['Lara Quinto', 'Nico Lim'],
        'cycle' => $this->evaluation->period->name,
        'title' => 'Close 40 deals', 'measure' => 'number', 'target' => 40, 'unit' => 'deals',
    ]);

    expect($result->failed())->toBeFalse()->and(PerformanceGoal::count())->toBe(2);

    $checkIn = perfAgent($this->staffUser, 'check_in_goal', ['goal' => '40 deals', 'value' => 30, 'health' => 'on_track']);

    expect($checkIn->failed())->toBeFalse()
        ->and($checkIn->cards[0]['meta'][0])->toBe('75% — 30 deals, target 40 deals')
        ->and(PerformanceGoal::where('employee_id', $other->id)->first()->current_value)->toEqual(0);

    $mine = perfAgent($otherUser, 'find_my_goals', ['cycle' => $this->evaluation->period->name]);

    expect($mine->cards)->toHaveCount(1);
});

test('a check-in names one of the user’s own goals, or says which they have', function () {
    app(GoalWorkflow::class)->set([$this->staff], $this->evaluation->period, ['title' => 'Ship the portal'], null, $this->hr);

    $result = perfAgent($this->staffUser, 'check_in_goal', ['goal' => 'Hire two engineers', 'value' => 50, 'health' => 'on_track']);

    expect($result->failed())->toBeTrue()->and($result->detail)->toContain('Ship the portal');
});

test('the reviews asked of the user come back waiting first', function () {
    [$peerUser, $peer] = perfParticipant();
    app(ReviewWorkflow::class)->request($this->evaluation, [$peer], $this->hr);

    $result = perfAgent($peerUser, 'find_my_reviews');

    expect($result->cards[0]['title'])->toStartWith('Lara Quinto')
        ->and($result->cards[0]['badge'])->toBe('Waiting');
});

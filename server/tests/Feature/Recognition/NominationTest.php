<?php

use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Recognition\NominationWorkflow;
use App\Support\Recognition\PointsLedger;
use App\Support\Recognition\RecognitionException;
use Illuminate\Support\Facades\Notification;

/*
| Nominate → approve (ADR 0071): anyone nominates a colleague with a reason; HR
| approves it into an award (with its points) or turns it down; the nominator
| hears either way.
*/

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    $this->nominator = User::factory()->create(['is_active' => true]);
    $this->nominatorEmployee = Employee::factory()->create(['user_id' => $this->nominator->id]);
    $this->nomineeUser = User::factory()->create(['is_active' => true]);
    $this->nominee = Employee::factory()->create(['user_id' => $this->nomineeUser->id, 'employment_status' => 'active']);
    $this->type = AwardType::create(['name' => 'Spot Award', 'is_active' => true, 'points' => 50]);
    $this->workflow = app(NominationWorkflow::class);
});

const NOMINATION_REASON = 'Stayed late three nights to get the client migration over the line.';

test('a colleague is nominated, and those who review nominations hear of it', function () {
    $nomination = $this->workflow->nominate($this->nominee, $this->type, NOMINATION_REASON, $this->nominator);

    expect($nomination->status)->toBe('pending')
        ->and($nomination->nominator_employee_id)->toBe($this->nominatorEmployee->id);

    Notification::assertSentTo($this->hr, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->title, 'New nomination')
        && $n->url === '/awards/nominations');
    Notification::assertNotSentTo($this->nominator, SystemNotification::class);
});

test('nobody nominates themselves, a closed award, an inactive colleague, or with too short a reason', function () {
    $closed = AwardType::create(['name' => 'Perfect Attendance', 'is_active' => true, 'accepts_nominations' => false]);
    $gone = Employee::factory()->create(['employment_status' => 'resigned']);

    expect(fn () => $this->workflow->nominate($this->nominatorEmployee, $this->type, NOMINATION_REASON, $this->nominator))
        ->toThrow(RecognitionException::class, 'You can’t nominate yourself.')
        ->and(fn () => $this->workflow->nominate($this->nominee, $closed, NOMINATION_REASON, $this->nominator))
        ->toThrow(RecognitionException::class, '“Perfect Attendance” isn’t open to nominations.')
        ->and(fn () => $this->workflow->nominate($gone, $this->type, NOMINATION_REASON, $this->nominator))
        ->toThrow(RecognitionException::class, 'Only active colleagues can be nominated.')
        ->and(fn () => $this->workflow->nominate($this->nominee, $this->type, 'Great job!', $this->nominator))
        ->toThrow(RecognitionException::class, 'at least 20 characters');
});

test('one pending nomination per person, award and nominator', function () {
    $this->workflow->nominate($this->nominee, $this->type, NOMINATION_REASON, $this->nominator);

    expect(fn () => $this->workflow->nominate($this->nominee, $this->type, NOMINATION_REASON.' Again.', $this->nominator))
        ->toThrow(RecognitionException::class, 'already nominated');
});

test('approving gives the award with its points, and tells the nominator and the recipient', function () {
    $nomination = $this->workflow->nominate($this->nominee, $this->type, NOMINATION_REASON, $this->nominator);

    $award = $this->workflow->approve($nomination, $this->hr, 'For carrying the migration home.', null);

    expect($nomination->refresh()->status)->toBe('approved')
        ->and($nomination->employee_award_id)->toBe($award->id)
        ->and($nomination->reviewed_by)->toBe($this->hr->id)
        ->and($award->reason)->toBe('For carrying the migration home.')
        ->and($award->awarded_by)->toBe($this->hr->id)
        ->and(app(PointsLedger::class)->balance($this->nominee))->toBe(50);

    Notification::assertSentTo($this->nominator, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->title, 'approved'));
    Notification::assertSentTo($this->nomineeUser, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->title, 'Spot Award'));
});

test('the citation defaults to the nominator’s reason', function () {
    $nomination = $this->workflow->nominate($this->nominee, $this->type, NOMINATION_REASON, $this->nominator);

    expect($this->workflow->approve($nomination, $this->hr, null, null)->reason)->toBe(NOMINATION_REASON);
});

test('turning one down records why and tells the nominator; a decided one cannot be decided again', function () {
    $nomination = $this->workflow->nominate($this->nominee, $this->type, NOMINATION_REASON, $this->nominator);

    $this->workflow->reject($nomination, $this->hr, 'Already recognised for this last month.');

    expect($nomination->refresh()->status)->toBe('rejected')
        ->and($nomination->review_note)->toBe('Already recognised for this last month.')
        ->and(EmployeeAward::query()->count())->toBe(0)
        ->and(fn () => $this->workflow->approve($nomination, $this->hr, null, null))
        ->toThrow(RecognitionException::class, 'already rejected');

    Notification::assertSentTo($this->nominator, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->body, 'Already recognised'));
});

test('a reviewer is never the nominator, nor the nominee', function () {
    $reviewerNominates = $this->workflow->nominate($this->nominee, $this->type, NOMINATION_REASON, $this->hr);
    $aboutReviewer = $this->workflow->nominate(Employee::factory()->create(['user_id' => $this->hr->id]), $this->type, NOMINATION_REASON, $this->nominator);

    expect(fn () => $this->workflow->approve($reviewerNominates, $this->hr, null, null))
        ->toThrow(RecognitionException::class, 'someone else has to review it')
        ->and(fn () => $this->workflow->reject($aboutReviewer, $this->hr, null))
        ->toThrow(RecognitionException::class, 'a nomination of yourself');
});

test('only the nominator withdraws, and only while it waits', function () {
    $nomination = $this->workflow->nominate($this->nominee, $this->type, NOMINATION_REASON, $this->nominator);

    expect(fn () => $this->workflow->withdraw($nomination, $this->hr))
        ->toThrow(RecognitionException::class, 'Only who nominated');

    $this->workflow->withdraw($nomination, $this->nominator);

    expect($nomination->refresh()->status)->toBe('withdrawn')
        ->and(fn () => $this->workflow->withdraw($nomination, $this->nominator))->toThrow(RecognitionException::class)
        ->and(AwardNomination::query()->pending()->count())->toBe(0);
});

<?php

use App\Models\AwardType;
use App\Models\Employee;
use App\Models\Kudos;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Awards\AwardWorkflow;
use App\Support\Recognition\KudosWorkflow;
use App\Support\Recognition\PointsLedger;
use App\Support\Recognition\RecognitionException;
use Illuminate\Support\Facades\Notification;

/*
| Points and kudos (ADR 0071): a ledger nothing edits in place — awards credit
| their type's points, kudos credit a few up to a monthly limit, and taking
| either back reverses what it gave.
*/

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    testOrganization()->update(['kudos_points' => 10, 'kudos_monthly_limit' => 2]);
    $this->ledger = app(PointsLedger::class);
    $this->sender = Employee::factory()->create(['user_id' => User::factory()->create(['is_active' => true])->id]);
    $this->recipientUser = User::factory()->create(['is_active' => true]);
    $this->recipient = Employee::factory()->create(['user_id' => $this->recipientUser->id, 'employment_status' => 'active']);
});

test('an award credits its points; changing its type posts the difference; removing it reverses them', function () {
    $spot = AwardType::create(['name' => 'Spot Award', 'is_active' => true, 'points' => 50]);
    $star = AwardType::create(['name' => 'Star of the Quarter', 'is_active' => true, 'points' => 200]);
    $awards = app(AwardWorkflow::class);

    $award = $awards->give($this->recipient, $spot, today(), 'Saved the release.', $this->hr);
    expect($this->ledger->balance($this->recipient))->toBe(50);

    $awards->revise($award, ['award_type_id' => $star->id]);
    expect($this->ledger->balance($this->recipient))->toBe(200);

    $awards->remove($award->refresh());
    expect($this->ledger->balance($this->recipient))->toBe(0)
        ->and($this->ledger->history($this->recipient)->pluck('amount')->all())->toBe([-200, 150, 50]);
});

test('an award with no points posts nothing', function () {
    $plain = AwardType::create(['name' => 'Thank You', 'is_active' => true]);

    app(AwardWorkflow::class)->give($this->recipient, $plain, today(), null, $this->hr);

    expect($this->ledger->history($this->recipient))->toHaveCount(0);
});

test('kudos credit points up to the monthly limit, then still go out without them', function () {
    $kudos = app(KudosWorkflow::class);

    $first = $kudos->send($this->sender, $this->recipient, 'Thanks for covering my shift!');
    $kudos->send($this->sender, $this->recipient, 'And for the onboarding notes.');
    $third = $kudos->send($this->sender, $this->recipient, 'Seriously, legend.');

    expect([$first->points, $third->points])->toBe([10, 0])
        ->and($this->ledger->balance($this->recipient))->toBe(20)
        ->and($this->ledger->kudosLeftThisMonth($this->sender))->toBe(0);

    Notification::assertSentToTimes($this->recipientUser, SystemNotification::class, 3);
});

test('kudos are not for yourself, nor empty, nor too long', function () {
    $kudos = app(KudosWorkflow::class);

    expect(fn () => $kudos->send($this->sender, $this->sender, 'Me, myself and I.'))
        ->toThrow(RecognitionException::class, 'Kudos are for someone else.')
        ->and(fn () => $kudos->send($this->sender, $this->recipient, '   '))
        ->toThrow(RecognitionException::class, 'Say what they did')
        ->and(fn () => $kudos->send($this->sender, $this->recipient, str_repeat('x', 501)))
        ->toThrow(RecognitionException::class, '500 characters');
});

test('taking kudos down reverses their points', function () {
    $sent = app(KudosWorkflow::class)->send($this->sender, $this->recipient, 'Thanks for covering my shift!');

    app(KudosWorkflow::class)->remove($sent, $this->hr);

    expect($this->ledger->balance($this->recipient))->toBe(0)
        ->and(Kudos::query()->count())->toBe(0)
        ->and(Kudos::withTrashed()->count())->toBe(1);
});

test('an adjustment needs a reason, is never one’s own, and is told to the person', function () {
    $this->ledger->adjust($this->recipient, 25, 'Prize for the safety quiz.', $this->hr);

    expect($this->ledger->balance($this->recipient))->toBe(25)
        ->and(fn () => $this->ledger->adjust($this->recipient, 5, '  ', $this->hr))
        ->toThrow(RecognitionException::class, 'Say why')
        ->and(fn () => $this->ledger->adjust(Employee::factory()->create(['user_id' => $this->hr->id]), 100, 'Bonus', $this->hr))
        ->toThrow(RecognitionException::class, 'your own points');

    Notification::assertSentTo($this->recipientUser, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->title, '25 points'));
});

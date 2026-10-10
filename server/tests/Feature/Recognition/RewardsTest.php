<?php

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Recognition\PointsLedger;
use App\Support\Recognition\RecognitionException;
use App\Support\Recognition\RewardWorkflow;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;

/*
| Rewards (ADR 0071): points are spent on the catalogue. Redeeming takes the
| points and the stock at once, so the same points are never spent twice; a
| cancelled or declined request gives both back.
*/

beforeEach(function () {
    Notification::fake();
    $this->hr = actingAsSuperAdmin();
    $this->user = User::factory()->create(['is_active' => true]);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    $this->ledger = app(PointsLedger::class);
    $this->ledger->adjust($this->employee, 100, 'Opening balance', $this->hr);
    $this->workflow = app(RewardWorkflow::class);
});

test('redeeming takes the points and one from stock, and HR hears of it', function () {
    $voucher = Reward::create(['name' => 'Coffee voucher', 'cost' => 60, 'stock' => 3]);

    $redemption = $this->workflow->redeem($this->employee, $voucher, 'Oat milk please');

    expect($redemption->status)->toBe('pending')
        ->and($redemption->cost)->toBe(60)
        ->and($this->ledger->balance($this->employee))->toBe(40)
        ->and($voucher->refresh()->stock)->toBe(2);

    Notification::assertSentTo($this->hr, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->title, 'Coffee voucher'));
});

test('the same points cannot be spent twice', function () {
    $voucher = Reward::create(['name' => 'Coffee voucher', 'cost' => 60]);

    $this->workflow->redeem($this->employee, $voucher, null);

    expect(fn () => $this->workflow->redeem($this->employee, $voucher, null))
        ->toThrow(RecognitionException::class, 'You have 40 points; Coffee voucher needs 60.');

    expect(RewardRedemption::query()->count())->toBe(1)
        ->and($this->ledger->balance($this->employee))->toBe(40);
});

test('an empty shelf or a retired reward is refused', function () {
    $gone = Reward::create(['name' => 'Tumbler', 'cost' => 10, 'stock' => 0]);
    $retired = Reward::create(['name' => 'Old mug', 'cost' => 10, 'is_active' => false]);

    expect(fn () => $this->workflow->redeem($this->employee, $gone, null))
        ->toThrow(RecognitionException::class, 'Tumbler is out of stock.')
        ->and(fn () => $this->workflow->redeem($this->employee, $retired, null))
        ->toThrow(RecognitionException::class, 'Old mug is no longer offered.');
});

test('cancelling or declining gives the points and the stock back', function () {
    $voucher = Reward::create(['name' => 'Coffee voucher', 'cost' => 30, 'stock' => 5]);
    $mine = $this->workflow->redeem($this->employee, $voucher, null);
    $other = $this->workflow->redeem($this->employee, $voucher, null);

    $this->workflow->cancel($mine, $this->employee);
    $this->workflow->decline($other, $this->hr, 'Supplier ran out.');

    expect($mine->refresh()->status)->toBe('cancelled')
        ->and($other->refresh()->status)->toBe('declined')
        ->and($other->response_note)->toBe('Supplier ran out.')
        ->and($this->ledger->balance($this->employee))->toBe(100)
        ->and($voucher->refresh()->stock)->toBe(5);

    Notification::assertSentTo($this->user, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->body, 'Supplier ran out.'));
});

test('fulfilling closes the request and tells the person; a decided one stays decided', function () {
    $voucher = Reward::create(['name' => 'Coffee voucher', 'cost' => 30]);
    $redemption = $this->workflow->redeem($this->employee, $voucher, null);

    $this->workflow->fulfil($redemption, $this->hr, 'At the front desk.');

    expect($redemption->refresh()->status)->toBe('fulfilled')
        ->and($redemption->handled_by)->toBe($this->hr->id)
        ->and($this->ledger->balance($this->employee))->toBe(70)
        ->and(fn () => $this->workflow->decline($redemption, $this->hr, null))
        ->toThrow(RecognitionException::class, 'already fulfilled')
        ->and(fn () => $this->workflow->cancel($redemption, $this->employee))
        ->toThrow(RecognitionException::class, 'already fulfilled');

    Notification::assertSentTo($this->user, SystemNotification::class, fn (SystemNotification $n): bool => str_contains($n->title, 'ready'));
});

test('nobody handles their own request, and only the owner cancels', function () {
    $hrEmployee = Employee::factory()->create(['user_id' => $this->hr->id]);
    $this->ledger->post($hrEmployee, 50, 'adjustment', null, 'Opening balance', null);
    $voucher = Reward::create(['name' => 'Coffee voucher', 'cost' => 30]);
    $own = $this->workflow->redeem($hrEmployee, $voucher, null);
    $theirs = $this->workflow->redeem($this->employee, $voucher, null);

    expect(fn () => $this->workflow->fulfil($own, $this->hr, null))
        ->toThrow(RecognitionException::class, 'your own request')
        ->and(fn () => $this->workflow->cancel($theirs, $hrEmployee))
        ->toThrow(RecognitionException::class, 'Only who asked');
});

test('another company’s points and rewards are out of reach', function () {
    $other = Organization::factory()->create();
    $theirReward = app(Tenancy::class)->runFor($other, fn () => Reward::create(['name' => 'Their prize', 'cost' => 1]));

    expect(Reward::query()->whereKey($theirReward->id)->exists())->toBeFalse()
        ->and(app(Tenancy::class)->runFor($other, fn () => $this->ledger->balance($this->employee)))->toBe(0);
});

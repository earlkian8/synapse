<?php

use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Support\Recognition\PointsLedger;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Recognition in the mobile app (ADR 0071): the wall, kudos, nominations, points
| and rewards, each self-scoped and through the same workflows as the web.
*/

beforeEach(function () {
    Notification::fake();
    $this->user = actingAsUserWith(['awards.participate']);
    $this->me = Employee::factory()->create(['user_id' => $this->user->id, 'employment_status' => 'active']);
    $this->colleague = Employee::factory()->create(['employment_status' => 'active', 'first_name' => 'Rosa', 'last_name' => 'Lim']);
    $this->type = AwardType::create(['name' => 'Spot Award', 'is_active' => true, 'points' => 40]);
    Sanctum::actingAs($this->user);
});

test('the app sends kudos and reads the wall with my summary', function () {
    $this->postJson('/api/kudos', ['to_employee_id' => $this->colleague->id, 'message' => 'Thanks for the handover!'])
        ->assertCreated()
        ->assertJsonPath('data.points', 10);

    $this->getJson('/api/recognition')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'kudos')
        ->assertJsonPath('data.0.from.name', $this->me->full_name)
        ->assertJsonPath('me.balance', 0)
        ->assertJsonPath('me.kudos_left', 4);
});

test('the app finds colleagues by name, never the person asking', function () {
    $this->getJson('/api/colleagues?search=rosa')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', $this->colleague->full_name)
        ->assertJsonMissingPath('data.0.email');

    expect(collect($this->getJson('/api/colleagues')->json('data'))->pluck('id'))->not->toContain($this->me->id);
});

test('the app nominates, lists and withdraws', function () {
    $this->getJson('/api/nominations/types')->assertOk()->assertJsonPath('data.0.name', 'Spot Award');

    $id = $this->postJson('/api/nominations', [
        'employee_id' => $this->colleague->id,
        'award_type_id' => $this->type->id,
        'reason' => 'Rebuilt the onboarding checklist from scratch.',
    ])->assertCreated()->json('data.id');

    $this->getJson('/api/nominations')->assertOk()->assertJsonPath('data.0.status', 'pending');
    $this->deleteJson("/api/nominations/{$id}")->assertOk()->assertJsonPath('data.status', 'withdrawn');
});

test('a refusal reaches the app as a 422 with the reason', function () {
    $this->postJson('/api/nominations', [
        'employee_id' => $this->me->id,
        'award_type_id' => $this->type->id,
        'reason' => 'Rebuilt the onboarding checklist from scratch.',
    ])->assertStatus(422)->assertJsonPath('errors.employee_id.0', 'You can’t nominate yourself.');
});

test('the app shows points, redeems and cancels', function () {
    app(PointsLedger::class)->post($this->me, 100, 'adjustment', null, 'Opening balance', null);
    $voucher = Reward::create(['name' => 'Coffee voucher', 'cost' => 60, 'stock' => 2]);

    $this->getJson('/api/points')->assertOk()->assertJsonPath('balance', 100)->assertJsonPath('history.0.note', 'Opening balance');
    $this->getJson('/api/rewards')->assertOk()->assertJsonPath('rewards.0.name', 'Coffee voucher')->assertJsonPath('rewards.0.affordable', true);

    $this->postJson("/api/rewards/{$voucher->hashid}/redeem", ['note' => 'Oat milk'])->assertCreated()->assertJsonPath('balance', 40);
    $this->postJson("/api/rewards/{$voucher->hashid}/redeem")->assertStatus(422)->assertJsonPath('message', 'You have 40 points; Coffee voucher needs 60.');

    $redemption = RewardRedemption::query()->sole();
    $this->patchJson("/api/redemptions/{$redemption->id}/cancel")->assertOk()->assertJsonPath('balance', 100);
});

test('someone else’s nomination or request is not found from the app', function () {
    $other = Employee::factory()->create(['employment_status' => 'active']);
    $theirs = AwardNomination::create(['award_type_id' => $this->type->id, 'employee_id' => $this->colleague->id, 'nominated_by' => null, 'reason' => 'Not mine to withdraw at all.']);
    app(PointsLedger::class)->post($other, 100, 'adjustment', null, null, null);
    $redemption = RewardRedemption::create(['reward_id' => Reward::create(['name' => 'Mug', 'cost' => 5])->id, 'employee_id' => $other->id, 'cost' => 5]);

    $this->deleteJson("/api/nominations/{$theirs->id}")->assertNotFound();
    $this->patchJson("/api/redemptions/{$redemption->id}/cancel")->assertNotFound();
});

test('without taking part the app gets a 403', function () {
    Sanctum::actingAs(actingAsUserWith(['leave.request']));

    $this->getJson('/api/recognition')->assertForbidden();
    $this->getJson('/api/points')->assertForbidden();
});

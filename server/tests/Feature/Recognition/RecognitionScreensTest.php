<?php

use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\Kudos;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Support\Recognition\PointsLedger;
use App\Support\Recognition\RewardWorkflow;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The recognition screens (ADR 0071): the wall, kudos, nominations and rewards
| for everybody taking part; the nomination queue, the shortlist and the rewards
| desk for HR.
*/

beforeEach(function () {
    Notification::fake();
    $this->user = actingAsUserWith(['awards.participate']);
    $this->me = Employee::factory()->create(['user_id' => $this->user->id, 'employment_status' => 'active']);
    $this->colleague = Employee::factory()->create(['employment_status' => 'active', 'first_name' => 'Rosa', 'last_name' => 'Lim']);
    $this->type = AwardType::create(['name' => 'Spot Award', 'is_active' => true, 'points' => 40]);
});

test('the wall shows kudos and awards, newest first, with my balance and what I can still give', function () {
    EmployeeAward::create(['employee_id' => $this->colleague->id, 'award_type_id' => $this->type->id, 'awarded_on' => today()->subDay(), 'reason' => 'Shipped it.']);
    $this->post(route('awards.kudos.store'), ['to_employee_id' => $this->colleague->id, 'message' => 'Thanks for the handover!'])->assertRedirect();
    assertToast('success', 'Kudos sent to Rosa');

    $this->get(route('awards.wall'))->assertInertia(fn (Assert $page) => $page
        ->component('awards/wall')
        ->has('feed', 2)
        ->where('feed.0.kind', 'kudos')
        ->where('feed.0.message', 'Thanks for the handover!')
        ->where('feed.0.to.name', $this->colleague->full_name)
        ->where('feed.1.kind', 'award')
        ->where('feed.1.award_type.name', 'Spot Award')
        ->where('me.balance', 0)
        ->where('me.kudos_left', 4)
        ->where('me.kudos_points', 10)
        ->where('can.manage', false)
        ->has('colleagues', 1)
        ->where('colleagues.0.name', $this->colleague->full_name));
});

test('kudos to oneself come back on the field', function () {
    $this->post(route('awards.kudos.store'), ['to_employee_id' => $this->me->id, 'message' => 'Me!'])
        ->assertSessionHasErrors(['to_employee_id' => 'Kudos are for someone else.']);
});

test('a colleague is nominated from the screen, and the nomination can be withdrawn', function () {
    $this->post(route('awards.my-nominations.store'), [
        'employee_id' => $this->colleague->id,
        'award_type_id' => $this->type->id,
        'reason' => 'Rebuilt the onboarding checklist from scratch.',
    ])->assertRedirect();
    assertToast('success', 'Nomination sent');

    $nomination = AwardNomination::query()->sole();

    $this->get(route('awards.my-nominations'))->assertInertia(fn (Assert $page) => $page
        ->component('awards/my-nominations')
        ->where('nominations.0.status', 'pending')
        ->where('nominations.0.nominee.name', $this->colleague->full_name)
        ->where('types.0.name', 'Spot Award'));

    $this->delete(route('awards.my-nominations.destroy', $nomination))->assertRedirect();

    expect($nomination->refresh()->status)->toBe('withdrawn');
});

test('rewards: the catalogue, my history, redeeming and cancelling', function () {
    app(PointsLedger::class)->post($this->me, 100, 'adjustment', null, 'Opening balance', null);
    $voucher = Reward::create(['name' => 'Coffee voucher', 'cost' => 60, 'stock' => 2]);

    $this->post(route('awards.rewards.redeem', $voucher), ['note' => 'Oat milk'])->assertRedirect();
    assertToast('success', 'Coffee voucher requested');
    $this->post(route('awards.rewards.redeem', $voucher))->assertRedirect();
    assertToast('warning', 'You have 40 points; Coffee voucher needs 60.');

    $this->get(route('awards.points'))->assertInertia(fn (Assert $page) => $page
        ->component('awards/points')
        ->where('me.balance', 40)
        ->where('rewards.0.name', 'Coffee voucher')
        ->where('rewards.0.stock', 1)
        ->where('redemptions.0.status', 'pending')
        ->where('history.0.amount', -60));

    $this->patch(route('awards.redemptions.cancel', RewardRedemption::query()->sole()))->assertRedirect();

    expect(app(PointsLedger::class)->balance($this->me))->toBe(100);
});

test('the module has one way in: someone who only takes part lands on the wall', function () {
    $this->get(route('awards.index'))->assertRedirect(route('awards.wall'));

    actingAsUserWith(['awards.view']);
    $this->get(route('awards.index'))->assertOk();

    actingAsUserWith(['leave.request']);
    $this->get(route('awards.index'))->assertForbidden();
});

test('without taking part there is no wall', function () {
    actingAsUserWith(['leave.request']);

    $this->get(route('awards.wall'))->assertForbidden();
    $this->post(route('awards.kudos.store'), ['to_employee_id' => $this->colleague->id, 'message' => 'Hi'])->assertForbidden();
});

test('HR reviews nominations: approve with an edited citation, or turn down', function () {
    $nominator = User::factory()->create(['is_active' => true]);
    $first = AwardNomination::create(['award_type_id' => $this->type->id, 'employee_id' => $this->colleague->id, 'nominated_by' => $nominator->id, 'reason' => 'Rebuilt the onboarding checklist from scratch.']);
    $second = AwardNomination::create(['award_type_id' => $this->type->id, 'employee_id' => $this->me->id, 'nominated_by' => $nominator->id, 'reason' => 'Covered the front desk for a week.']);
    actingAsSuperAdmin();

    $this->get(route('awards.nominations'))->assertInertia(fn (Assert $page) => $page
        ->component('awards/nominations')
        ->has('nominations', 2)
        ->where('counts.pending', 2)
        ->where('nominations.0.reason', 'Rebuilt the onboarding checklist from scratch.'));

    $this->post(route('awards.nominations.approve', $first), ['citation' => 'For a checklist that finally works.'])->assertRedirect();
    assertToast('success', 'Approved');
    $this->post(route('awards.nominations.reject', $second), ['note' => 'Not this month.'])->assertRedirect();
    assertToast('success', 'Turned down');

    expect($first->refresh()->award->reason)->toBe('For a checklist that finally works.')
        ->and($second->refresh()->status)->toBe('rejected')
        ->and(app(PointsLedger::class)->balance($this->colleague))->toBe(40);
});

test('the AI shortlist now lives at its own address', function () {
    actingAsSuperAdmin();

    $this->get(route('awards.shortlist'))->assertInertia(fn (Assert $page) => $page->component('awards/shortlist')->has('board'));
});

test('HR runs the rewards desk: catalogue, requests, adjustments and settings', function () {
    $requester = Employee::factory()->create(['user_id' => User::factory()->create(['is_active' => true])->id]);
    app(PointsLedger::class)->post($requester, 500, 'adjustment', null, 'Opening balance', null);
    actingAsSuperAdmin();

    $this->post(route('awards.rewards.store'), ['name' => 'Half day off', 'cost' => 300, 'stock' => null, 'is_active' => true])->assertRedirect();
    assertToast('success', 'Reward added.');
    $reward = Reward::query()->sole();
    $redemption = app(RewardWorkflow::class)->redeem($requester, $reward, null);

    $this->get(route('awards.rewards'))->assertInertia(fn (Assert $page) => $page
        ->component('awards/rewards')
        ->where('rewards.0.name', 'Half day off')
        ->where('redemptions.0.status', 'pending')
        ->where('settings.kudos_points', 10)
        ->where('counts.pending', 1));

    $this->post(route('awards.redemptions.fulfil', $redemption), ['note' => 'Enjoy!'])->assertRedirect();
    $this->post(route('awards.points.adjust'), ['employee_id' => $requester->id, 'amount' => -50, 'note' => 'Duplicate credit'])->assertRedirect();
    $this->post(route('awards.recognition-settings'), ['kudos_points' => 5, 'kudos_monthly_limit' => 8])->assertRedirect();
    $this->post(route('awards.rewards.update', $reward), ['name' => 'Half day off', 'cost' => 250, 'is_active' => true])->assertRedirect();
    $this->delete(route('awards.rewards.destroy', $reward))->assertRedirect();

    expect($redemption->refresh()->status)->toBe('fulfilled')
        ->and(app(PointsLedger::class)->balance($requester))->toBe(150)
        ->and(testOrganization()->refresh()->kudos_points)->toBe(5)
        ->and($reward->refresh()->trashed())->toBeTrue()
        ->and($reward->cost)->toBe(250);
});

test('HR takes kudos down from the wall', function () {
    $this->post(route('awards.kudos.store'), ['to_employee_id' => $this->colleague->id, 'message' => 'Off-colour joke']);
    $kudos = Kudos::query()->sole();

    $this->delete(route('awards.kudos.destroy', $kudos))->assertForbidden();

    actingAsSuperAdmin();
    $this->delete(route('awards.kudos.destroy', $kudos))->assertRedirect();

    expect(Kudos::query()->count())->toBe(0);
});

test('another company’s nominations and rewards cannot be reached by address', function () {
    $other = Organization::factory()->create();
    [$nomination, $reward] = app(Tenancy::class)->runFor($other, function () {
        $type = AwardType::create(['name' => 'Theirs', 'is_active' => true]);

        return [
            AwardNomination::create(['award_type_id' => $type->id, 'employee_id' => Employee::factory()->create()->id, 'reason' => 'Their reason, long enough to count.']),
            Reward::create(['name' => 'Their prize', 'cost' => 1]),
        ];
    });
    actingAsSuperAdmin();
    app(Tenancy::class)->forget();

    $this->post(route('awards.nominations.approve', $nomination))->assertNotFound();
    $this->post(route('awards.rewards.redeem', $reward->hashid))->assertNotFound();
});

test('an award entered today for last week sits with last week on the wall', function () {
    EmployeeAward::create(['employee_id' => $this->colleague->id, 'award_type_id' => $this->type->id, 'awarded_on' => today()->subDays(7), 'reason' => 'Backdated.']);
    $this->post(route('awards.kudos.store'), ['to_employee_id' => $this->colleague->id, 'message' => 'Yesterday’s thanks.']);
    Kudos::query()->update(['created_at' => now()->subDays(2)]);

    $this->get(route('awards.wall'))->assertInertia(fn (Assert $page) => $page
        ->where('feed.0.kind', 'kudos')
        ->where('feed.1.kind', 'award'));
});

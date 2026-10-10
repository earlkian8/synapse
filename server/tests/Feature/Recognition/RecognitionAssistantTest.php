<?php

use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\Kudos;
use App\Models\Reward;
use App\Models\User;
use App\Services\Assistant\Modules\AwardsModule;
use App\Services\Assistant\ToolResult;
use App\Support\Recognition\PointsLedger;
use App\Support\Recognition\RewardWorkflow;
use Illuminate\Support\Facades\Notification;

/*
| The assistant and ADR 0071: kudos, nominations, points and rewards — through
| the same workflows as the screens; what tells another person waits for Confirm.
*/

function recognitionAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(AwardsModule::class)->run($user, $tool, $args);
}

beforeEach(function () {
    Notification::fake();
    testOrganization();
    $this->type = AwardType::create(['name' => 'Spot Award', 'is_active' => true, 'points' => 40]);
});

test('someone who only takes part gets the self-service tools, and the ones that tell others wait for Confirm', function () {
    $user = actingAsUserWith(['awards.participate']);
    $tools = array_column(app(AwardsModule::class)->tools($user), 'name');
    $module = app(AwardsModule::class);

    expect($module->isAvailable($user))->toBeTrue()
        ->and($tools)->toEqualCanonicalizing(['give_kudos', 'nominate_colleague', 'get_my_points'])
        ->and($module->requiresConfirmation('give_kudos'))->toBeTrue()
        ->and($module->requiresConfirmation('nominate_colleague'))->toBeTrue()
        ->and($module->requiresConfirmation('review_nomination'))->toBeTrue()
        ->and($module->requiresConfirmation('handle_redemption'))->toBeTrue();
});

test('kudos and a nomination from chat go through the workflows', function () {
    $user = actingAsUserWith(['awards.participate']);
    $me = Employee::factory()->create(['user_id' => $user->id]);
    $rosa = Employee::factory()->create(['first_name' => 'Rosa', 'middle_name' => null, 'last_name' => 'Lim', 'suffix' => null, 'employment_status' => 'active']);

    $kudos = recognitionAgent($user, 'give_kudos', ['employee' => 'Rosa Lim', 'message' => 'Thanks for the handover notes!']);
    $nomination = recognitionAgent($user, 'nominate_colleague', ['employee' => 'Rosa Lim', 'award_type' => 'spot award', 'reason' => 'Rebuilt the whole onboarding checklist alone.']);
    $self = recognitionAgent($user, 'give_kudos', ['employee' => $me->full_name, 'message' => 'Me!']);
    $points = recognitionAgent($user, 'get_my_points');

    expect($kudos->status)->toBe('done')
        ->and(Kudos::query()->sole()->to_employee_id)->toBe($rosa->id)
        ->and($nomination->status)->toBe('done')
        ->and(AwardNomination::query()->sole()->reason)->toBe('Rebuilt the whole onboarding checklist alone.')
        ->and($self->status)->toBe('error')
        ->and($self->detail)->toBe('Kudos are for someone else.')
        ->and($points->label)->toContain('0 points');
});

test('HR finds and approves a nomination, with its points', function () {
    $nominator = User::factory()->create(['is_active' => true]);
    $rosa = Employee::factory()->create(['first_name' => 'Rosa', 'middle_name' => null, 'last_name' => 'Lim', 'suffix' => null]);
    AwardNomination::create(['award_type_id' => $this->type->id, 'employee_id' => $rosa->id, 'nominated_by' => $nominator->id, 'reason' => 'Rebuilt the whole onboarding checklist alone.']);
    $hr = actingAsSuperAdmin();

    $found = recognitionAgent($hr, 'find_nominations');
    $approved = recognitionAgent($hr, 'review_nomination', ['employee' => 'Rosa Lim', 'award_type' => 'Spot Award', 'decision' => 'approve', 'citation' => 'For the checklist that works.']);
    $again = recognitionAgent($hr, 'review_nomination', ['employee' => 'Rosa Lim', 'decision' => 'reject']);

    expect($found->cards)->toHaveCount(1)
        ->and($found->cards[0]['title'])->toContain('Rosa Lim')
        ->and($approved->status)->toBe('done')
        ->and(app(PointsLedger::class)->balance($rosa))->toBe(40)
        ->and($again->detail)->toContain('No pending nomination');
});

test('HR sees requests for rewards and hands one over', function () {
    $user = User::factory()->create(['is_active' => true]);
    $rosa = Employee::factory()->create(['user_id' => $user->id, 'first_name' => 'Rosa', 'middle_name' => null, 'last_name' => 'Lim', 'suffix' => null]);
    app(PointsLedger::class)->post($rosa, 100, 'adjustment', null, null, null);
    app(RewardWorkflow::class)->redeem($rosa, Reward::create(['name' => 'Coffee voucher', 'cost' => 60]), null);
    $hr = actingAsSuperAdmin();

    $found = recognitionAgent($hr, 'find_redemptions');
    $handled = recognitionAgent($hr, 'handle_redemption', ['employee' => 'Rosa Lim', 'reward' => 'coffee voucher', 'decision' => 'fulfil']);
    $balance = recognitionAgent($hr, 'get_my_points', ['employee' => 'Rosa Lim']);

    expect($found->cards[0]['title'])->toContain('Coffee voucher')
        ->and($handled->status)->toBe('done')
        ->and($balance->label)->toContain('40 points');
});

test('nobody reads someone else’s points without managing awards', function () {
    $user = actingAsUserWith(['awards.participate']);
    Employee::factory()->create(['user_id' => $user->id]);
    Employee::factory()->create(['first_name' => 'Rosa', 'middle_name' => null, 'last_name' => 'Lim', 'suffix' => null]);

    $result = recognitionAgent($user, 'get_my_points', ['employee' => 'Rosa Lim']);

    expect($result->status)->toBe('error')->and($result->detail)->toContain('only your own');
});

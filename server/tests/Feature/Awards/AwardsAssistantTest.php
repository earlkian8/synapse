<?php

use App\Models\ActivityLog;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Modules\AwardsModule;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationClock;
use App\Support\Tenancy;

/*
| The awards capability of the assistant: the feed, the nomination board and
| giving recognition — driven by tool name, never through Gemini.
*/

function awardsAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(AwardsModule::class)->run($user, $tool, $args);
}

function awardsAgentTools(User $user): array
{
    return array_column(app(AwardsModule::class)->tools($user), 'name');
}

function awardType(string $name, bool $active = true): AwardType
{
    return AwardType::create(['name' => $name, 'is_active' => $active, 'color' => '#f59e0b']);
}

function recipient(string $first, string $last): Employee
{
    return Employee::factory()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null, 'employment_status' => 'active']);
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('a viewer reads the feed; the nomination board and every change need awards.manage', function () {
    $viewer = actingAsUserWith(['awards.view']);

    expect(awardsAgentTools($viewer))->toEqualCanonicalizing(['find_awards', 'list_award_types', 'awards_summary'])
        ->and(awardsAgent($viewer, 'get_award_nominees')->detail)->toContain('nomination board');

    $manager = actingAsUserWith(['awards.view', 'awards.manage']);

    expect(awardsAgentTools($manager))->toContain('get_award_nominees', 'give_award', 'update_award', 'remove_award');
});

test('only taking a recognition back waits for confirmation', function () {
    $module = app(AwardsModule::class);

    expect($module->requiresConfirmation('remove_award'))->toBeTrue()
        ->and($module->requiresConfirmation('give_award'))->toBeFalse()
        ->and($module->isReadOnly('get_award_nominees'))->toBeTrue()
        ->and($module->isReadOnly('give_award'))->toBeFalse();
});

// ── Doing ────────────────────────────────────────────────────────────────────

test('giving an award records who gave it, today by the office calendar', function () {
    $user = actingAsSuperAdmin();
    awardType('Spot Award');
    $maria = recipient('Maria', 'Santos');

    $result = awardsAgent($user, 'give_award', ['employee' => 'Maria Santos', 'award_type' => 'spot award', 'reason' => 'Rescued the Q3 close.']);
    $award = EmployeeAward::query()->where('employee_id', $maria->id)->firstOrFail();

    expect($result->failed())->toBeFalse()
        ->and($result->cards[0]['kind'])->toBe('award')
        ->and($award->awarded_by)->toBe($user->id)
        ->and($award->awarded_on->toDateString())->toBe(OrganizationClock::today())
        ->and(ActivityLog::query()->where('log_name', 'awards')->latest('id')->value('description'))->toBe('Recognised Maria Santos — Spot Award via assistant');
});

test('a retired award type and a future date are refused in the screens’ words', function () {
    $user = actingAsSuperAdmin();
    awardType('Old Award', active: false);
    awardType('Spot Award');
    recipient('Maria', 'Santos');

    $retired = awardsAgent($user, 'give_award', ['employee' => 'Maria Santos', 'award_type' => 'Old Award']);
    $future = awardsAgent($user, 'give_award', ['employee' => 'Maria Santos', 'award_type' => 'Spot Award', 'awarded_on' => today()->addDays(5)->toDateString()]);

    expect($retired->failed())->toBeTrue()
        ->and($retired->detail)->toBe('“Old Award” is no longer given out.')
        ->and($future->failed())->toBeTrue()
        ->and(EmployeeAward::query()->count())->toBe(0);
});

test('an award is found by its person, and by its type or date when they have several', function () {
    $user = actingAsSuperAdmin();
    $spot = awardType('Spot Award');
    $star = awardType('Star Performer');
    $maria = recipient('Maria', 'Santos');

    EmployeeAward::create(['employee_id' => $maria->id, 'award_type_id' => $spot->id, 'awarded_on' => '2026-03-01']);
    EmployeeAward::create(['employee_id' => $maria->id, 'award_type_id' => $star->id, 'awarded_on' => '2026-06-01']);

    $ambiguous = awardsAgent($user, 'update_award', ['employee' => 'Maria Santos', 'reason' => 'Updated']);

    expect($ambiguous->failed())->toBeTrue()
        ->and($ambiguous->detail)->toContain('Spot Award on 2026-03-01')->toContain('Say which');

    $revised = awardsAgent($user, 'update_award', ['employee' => 'Maria Santos', 'award_type' => 'Spot Award', 'reason' => 'For the migration.']);

    expect($revised->failed())->toBeFalse()
        ->and(EmployeeAward::query()->where('award_type_id', $spot->id)->value('reason'))->toBe('For the migration.');

    // Switching an award to a retired type is refused; keeping one is fine.
    $star->update(['is_active' => false]);

    expect(awardsAgent($user, 'update_award', ['employee' => 'Maria Santos', 'award_type' => 'Spot Award', 'new_award_type' => 'Star Performer'])->failed())->toBeTrue()
        ->and(awardsAgent($user, 'update_award', ['employee' => 'Maria Santos', 'award_type' => 'Star Performer', 'reason' => 'Still counts.'])->failed())->toBeFalse();

    expect(awardsAgent($user, 'remove_award', ['employee' => 'Maria Santos', 'awarded_on' => '2026-06-01'])->failed())->toBeFalse()
        ->and(EmployeeAward::query()->count())->toBe(1);
});

test('another workspace’s people cannot be recognised', function () {
    $user = actingAsSuperAdmin();
    awardType('Spot Award');

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => recipient('Zed', 'Outsider'));

    $result = awardsAgent($user, 'give_award', ['employee' => 'Zed Outsider', 'award_type' => 'Spot Award']);

    expect($result->failed())->toBeTrue()
        ->and(EmployeeAward::query()->count())->toBe(0);
});

// ── Reading ──────────────────────────────────────────────────────────────────

test('the nominees read is the board’s own ranking, breakdown included', function () {
    $user = actingAsSuperAdmin();
    awardType('Long Service Award');
    Employee::factory()->create(['first_name' => 'Old', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Timer', 'employment_status' => 'active', 'date_hired' => today()->subYears(12)]);
    Employee::factory()->create(['first_name' => 'New', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Hire', 'employment_status' => 'active', 'date_hired' => today()->subMonths(3)]);

    $result = awardsAgent($user, 'get_award_nominees', ['award_type' => 'Long Service Award']);

    expect($result->failed())->toBeFalse()
        ->and($result->cards[0]['title'])->toBe('Old Timer')
        ->and($result->cards[0]['badge'])->toBe('#1')
        ->and(implode(' | ', $result->cards[0]['meta']))->toContain('Tenure: 12');
});

test('a person’s brief lists their recognitions — their own even without awards.view', function () {
    actingAsSuperAdmin();
    $spot = awardType('Spot Award');
    $maria = recipient('Maria', 'Santos');
    EmployeeAward::create(['employee_id' => $maria->id, 'award_type_id' => $spot->id, 'awarded_on' => '2026-05-01', 'reason' => 'Great work.']);

    $asker = actingAsUserWith([]);
    $own = app(AwardsModule::class)->contextFor($asker, RetrievedSubject::employee($maria, isSelf: true));
    $other = app(AwardsModule::class)->contextFor($asker, RetrievedSubject::employee($maria));

    expect($own?->toPrompt())->toContain('Spot Award on May 1, 2026 — Great work.')
        ->and($other)->toBeNull();
});

test('a question about recognition reads the feed — and the front-runners for those who may see them', function () {
    actingAsSuperAdmin();
    $spot = awardType('Spot Award');
    EmployeeAward::create(['employee_id' => recipient('Maria', 'Santos')->id, 'award_type_id' => $spot->id, 'awarded_on' => today()->toDateString()]);

    $viewer = actingAsUserWith(['awards.view']);
    $brief = app(Retriever::class)->retrieve($viewer, 'any awards given this month?');

    expect($brief?->toPrompt())->toContain('Recognitions: 1 all-time')->toContain('Maria Santos — Spot Award')
        ->not->toContain('Front-runners');

    $manager = actingAsUserWith(['awards.view', 'awards.manage']);

    expect(app(Retriever::class)->retrieve($manager, 'who should get the next award?')?->toPrompt())->toContain('Front-runners on the nomination board');
});

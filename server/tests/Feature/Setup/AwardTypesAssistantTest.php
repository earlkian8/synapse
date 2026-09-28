<?php

use App\Models\ActivityLog;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Modules\AwardTypesModule;
use App\Services\Assistant\ToolResult;
use App\Support\Tenancy;

/*
| The award-types capability of the assistant: the catalogue of recognitions,
| changed by the Award Types screen's own rules — counts of use, never who
| received one. Gemini is never called.
*/

beforeEach(function () {
    $this->travelTo('2026-09-28 10:00:00');
});

function awardTypesAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(AwardTypesModule::class)->run($user, $tool, $args);
}

test('a viewer reads; a manager changes; only archiving waits for a confirm', function () {
    $tools = fn (User $user): array => array_column(app(AwardTypesModule::class)->tools($user), 'name');

    expect($tools(actingAsUserWith(['setup.award-types.view'])))->toEqualCanonicalizing(['find_award_types', 'get_award_type'])
        ->and($tools(actingAsUserWith(['setup.award-types.view', 'setup.award-types.manage'])))->toContain('create_award_type', 'archive_award_type')
        ->and(app(AwardTypesModule::class)->requiresConfirmation('archive_award_type'))->toBeTrue()
        ->and(app(AwardTypesModule::class)->requiresConfirmation('update_award_type'))->toBeFalse();
});

test('a type is created once, retired and reactivated, and every change is recorded', function () {
    $user = actingAsSuperAdmin();

    $created = awardTypesAgent($user, 'create_award_type', ['name' => 'Perfect Attendance', 'description' => 'No absences all quarter']);
    $again = awardTypesAgent($user, 'create_award_type', ['name' => 'perfect attendance']);
    $retired = awardTypesAgent($user, 'update_award_type', ['award_type' => 'perfect', 'active' => false]);

    expect($created->failed())->toBeFalse()
        ->and($again->detail)->toContain('already an award type called')
        ->and($retired->cards[0]['badge'])->toBe('Retired')
        ->and(AwardType::query()->sole()->is_active)->toBeFalse()
        ->and(ActivityLog::query()->where('log_name', 'company-setup')->pluck('description')->all())->toBe([
            'Created award type "Perfect Attendance" via assistant',
            'Updated award type "Perfect Attendance" via assistant',
        ]);
});

test('a type reads out how often it was given, never to whom', function () {
    $user = actingAsSuperAdmin();
    $star = AwardType::create(['name' => 'Star of the Month', 'is_active' => true]);
    $maria = Employee::factory()->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null]);
    EmployeeAward::create(['employee_id' => $maria->id, 'award_type_id' => $star->id, 'awarded_on' => '2026-08-01', 'awarded_by' => $user->id]);
    EmployeeAward::create(['employee_id' => $maria->id, 'award_type_id' => $star->id, 'awarded_on' => '2025-05-01', 'awarded_by' => $user->id]);

    $card = awardTypesAgent($user, 'get_award_type', ['award_type' => 'star'])->cards[0];

    expect(implode(' | ', $card['meta']))->toContain('Given 2 times in all, 1 in 2026')->toContain('Last given 2026-08-01')
        ->and(json_encode($card))->not->toContain('Maria');

    expect(app(AwardTypesModule::class)->consequence($user, 'archive_award_type', ['award_type' => 'Star of the Month']))->toContain('given 2 times');
});

test('archiving and restoring go through the workflow, and another workspace’s types never show', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();
    AwardType::create(['name' => 'Team Player', 'is_active' => true]);

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => AwardType::create(['name' => 'Their Award', 'is_active' => true]));
    app(Tenancy::class)->set($mine);

    awardTypesAgent($user, 'archive_award_type', ['award_type' => 'Team Player']);
    expect(AwardType::query()->count())->toBe(0);

    awardTypesAgent($user, 'restore_award_type', ['award_type' => 'Team Player']);

    expect(array_column(awardTypesAgent($user, 'find_award_types')->cards, 'title'))->toBe(['Team Player'])
        ->and(awardTypesAgent($user, 'get_award_type', ['award_type' => 'Their Award'])->failed())->toBeTrue();
});

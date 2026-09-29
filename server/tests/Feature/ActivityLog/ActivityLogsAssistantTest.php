<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Modules\ActivityLogsModule;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationClock;
use App\Support\Tenancy;

/*
| The activity-log capability of the assistant: the audit trail, searched by
| who, what and when — read-only, and without the request details (IP address,
| browser) the screen shows. Gemini is never called.
*/

function auditAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(ActivityLogsModule::class)->run($user, $tool, $args);
}

/** An audit entry by somebody, at a moment on the organisation's clock. */
function audited(?User $causer, string $event, string $description, string $area, string $at): ActivityLog
{
    $log = ActivityLog::create([
        'log_name' => $area,
        'event' => $event,
        'description' => $description,
        'causer_id' => $causer?->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Secret Browser 1.0',
    ]);
    $log->forceFill(['created_at' => OrganizationClock::parse($at)->utc()])->save();

    return $log;
}

test('it is read-only and needs the screen’s permission', function () {
    $module = app(ActivityLogsModule::class);

    expect($module->isAvailable(actingAsUserWith(['users.view'])))->toBeFalse()
        ->and(array_column($module->tools(actingAsUserWith(['activity-logs.view', 'activity-logs.delete'])), 'name'))->toBe(['find_activity', 'activity_summary'])
        ->and($module->isReadOnly('find_activity'))->toBeTrue()
        ->and($module->isReadOnly('activity_summary'))->toBeTrue();
});

test('the trail is searched by who, what, where and when — without request details', function () {
    $user = actingAsSuperAdmin();
    $jon = User::factory()->create(['first_name' => 'Jon', 'middle_name' => null, 'last_name' => 'Doe', 'suffix' => null]);
    audited($jon, 'archived', 'Archived employee Maria Santos', 'employees', '2026-09-20 09:00');
    audited($jon, 'updated', 'Updated leave balance for Maria Santos', 'leave', '2026-09-22 14:30');
    audited($user, 'created', 'Created job posting Analyst', 'recruitment', '2026-09-22 10:00');
    audited(null, 'deleted', 'Purged old sessions', 'system', '2026-09-23 03:00');

    $byJon = auditAgent($user, 'find_activity', ['person' => 'Jon Doe']);
    $aboutMaria = collect(auditAgent($user, 'find_activity', ['search' => 'maria santos', 'event' => 'archived'])->cards)->pluck('title')->all();
    $onThe22nd = collect(auditAgent($user, 'find_activity', ['since' => '2026-09-22', 'until' => '2026-09-22'])->cards)->pluck('title')->all();
    $recruiting = auditAgent($user, 'find_activity', ['area' => 'Recruitment']);
    $badDate = auditAgent($user, 'find_activity', ['since' => 'last week']);
    $everything = json_encode(auditAgent($user, 'find_activity')->cards);

    expect($byJon->label)->toBe('Searched the activity log — by Jon Doe')
        ->and(collect($byJon->cards)->pluck('title')->all())->toBe(['Updated leave balance for Maria Santos', 'Archived employee Maria Santos'])
        ->and($byJon->cards[0]['meta'][1])->toStartWith('Sep 22, 2026 2:30 PM')
        ->and($aboutMaria)->toBe(['Archived employee Maria Santos'])
        ->and($onThe22nd)->toBe(['Updated leave balance for Maria Santos', 'Created job posting Analyst'])
        ->and($recruiting->cards[0]['title'])->toBe('Created job posting Analyst')
        ->and($badDate->detail)->toContain('is not a date')
        ->and($everything)->not->toContain('203.0.113.7')
        ->and($everything)->not->toContain('Secret Browser')
        ->and($everything)->toContain('"subtitle":"System"');
});

test('a period is summarised by area, event and person', function () {
    $user = actingAsSuperAdmin();
    $jon = User::factory()->create(['first_name' => 'Jon', 'middle_name' => null, 'last_name' => 'Doe', 'suffix' => null]);
    audited($jon, 'updated', 'Updated leave balance', 'leave', '2026-09-22 14:30');
    audited($jon, 'updated', 'Updated leave balance again', 'leave', '2026-09-22 15:30');
    audited($user, 'created', 'Created job posting', 'recruitment', '2026-09-22 10:00');
    audited($user, 'created', 'Long ago', 'recruitment', '2026-01-01 10:00');

    $card = auditAgent($user, 'activity_summary', ['since' => '2026-09-01', 'until' => '2026-09-30'])->cards[0];

    expect($card['title'])->toBe('3 entries 2026-09-01 to 2026-09-30')
        ->and($card['meta'][0])->toBe('By area: Leave 2, Recruitment 1')
        ->and($card['meta'][1])->toBe('By event: Updated 2, Created 1')
        ->and($card['meta'][2])->toBe("Most active: Jon Doe 2, {$user->full_name} 1");
});

test('another workspace’s trail is never read', function () {
    $user = actingAsSuperAdmin();
    $mine = testOrganization();
    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => ActivityLog::create(['log_name' => 'system', 'event' => 'created', 'description' => 'Their secret entry']));
    app(Tenancy::class)->set($mine);

    expect(auditAgent($user, 'find_activity', ['search' => 'secret'])->cards)->toBe([]);
});

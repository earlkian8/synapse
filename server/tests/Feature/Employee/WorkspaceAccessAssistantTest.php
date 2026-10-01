<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use App\Services\Assistant\Modules\WorkspaceAccessModule;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationProvisioner;
use App\Support\WorkspaceJoin;
use Illuminate\Support\Facades\Notification;

/*
| The app-access capability (ADR 0059): join requests, invitations and the
| company join code. Every write that lets somebody in waits for a Confirm; an
| invitation goes only to the address on file; the code is never read out.
*/

beforeEach(fn () => Notification::fake());

function accessAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(WorkspaceAccessModule::class)->run($user, $tool, $args);
}

test('a join request is approved onto a roster line, or declined', function () {
    $organization = testOrganization();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();
    $organization->update(['join_code_enabled' => true]);
    $organization->refresh();
    $hr = actingAsUserWith(['employees.invite']);
    $line = Employee::factory()->create(['first_name' => 'Pia', 'middle_name' => null, 'last_name' => 'Pending', 'suffix' => null, 'email' => 'pia.work@example.com']);
    $pia = User::factory()->unaffiliated()->create(['first_name' => 'Pia', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Pending', 'email' => 'pia@home.test']);
    $other = User::factory()->unaffiliated()->create(['first_name' => 'Otto', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Other', 'email' => 'otto@home.test']);
    WorkspaceJoin::join($organization->join_code, $pia);
    WorkspaceJoin::join($organization->join_code, $other);
    $module = app(WorkspaceAccessModule::class);

    expect(collect(accessAgent($hr, 'find_join_requests')->cards)->pluck('subtitle')->all())->toBe(['pia@home.test', 'otto@home.test'])
        ->and($module->requiresConfirmation('approve_join_request'))->toBeTrue()
        ->and($module->consequence($hr, 'approve_join_request', ['person' => 'pia@home.test', 'employee' => 'Pia Pending']))->toContain('would sign in as Pia Pending');

    $approved = accessAgent($hr, 'approve_join_request', ['person' => 'pia@home.test', 'employee' => 'Pia Pending']);
    $declined = accessAgent($hr, 'decline_join_request', ['person' => 'Otto Other', 'reason' => 'Not on our roster']);

    expect($approved->failed())->toBeFalse()
        ->and($line->fresh()->user_id)->toBe($pia->id)
        ->and($declined->failed())->toBeFalse()
        ->and(OrganizationJoinRequest::query()->pending()->count())->toBe(0)
        ->and(ActivityLog::query()->where('description', "Declined Otto Other's request to join via assistant")->exists())->toBeTrue();
});

test('an invitation goes only to the address on file, and the join code is never read out', function () {
    $organization = testOrganization();
    $organization->rotateJoinCode();
    expect($organization->fresh()->join_code)->not->toBeEmpty();
    $hr = actingAsUserWith(['employees.invite']);
    Employee::factory()->create(['first_name' => 'Ivy', 'middle_name' => null, 'last_name' => 'Invite', 'suffix' => null, 'email' => 'ivy@company.test', 'user_id' => null]);
    $module = app(WorkspaceAccessModule::class);

    $invite = $module->tools($hr)[array_search('invite_to_app', array_column($module->tools($hr), 'name'), true)];
    $sent = accessAgent($hr, 'invite_to_app', ['employee' => 'Ivy Invite', 'email' => 'attacker@evil.test']);
    $status = json_encode(accessAgent($hr, 'get_join_code_status')->cards);

    expect(array_keys($invite['parameters']['properties']))->toBe(['employee'])
        ->and($module->requiresConfirmation('invite_to_app'))->toBeTrue()
        ->and($sent->detail)->toBe('The invitation went to ivy@company.test.')
        ->and(EmployeeInvitation::query()->sole()->email)->toBe('ivy@company.test')
        ->and($status)->not->toContain((string) $organization->fresh()->join_code)
        ->and(array_column($module->tools($hr), 'name'))->not->toContain('set_join_code')
        ->and(accessAgent($hr, 'set_join_code', ['enabled' => false])->detail)->toContain("don't have permission");
});

test('joining by code is switched and the code replaced by someone who manages the company', function () {
    $organization = testOrganization();
    $organization->rotateJoinCode();
    $organization->update(['join_code_enabled' => true]);
    $before = $organization->fresh()->join_code;
    $owner = actingAsUserWith(['employees.invite', 'setup.company.manage']);

    $result = accessAgent($owner, 'set_join_code', ['replace' => true, 'enabled' => false]);

    expect($result->detail)->toContain('Generated a new join code')->toContain('Joining by code is now off.')
        ->and($result->detail)->not->toContain((string) $organization->fresh()->join_code)
        ->and($organization->fresh()->join_code)->not->toBe($before)
        ->and($organization->fresh()->join_code_enabled)->toBeFalse();
});

test('a request named exactly is that one, even when a longer name also matches', function () {
    $organization = testOrganization();
    $organization->rotateJoinCode();
    $organization->update(['join_code_enabled' => true]);
    $hr = actingAsUserWith(['employees.invite']);
    $pia = User::factory()->unaffiliated()->create(['first_name' => 'Pia', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Pending', 'email' => 'pia@home.test']);
    $longer = User::factory()->unaffiliated()->create(['first_name' => 'Pia', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Pendington', 'email' => 'pia.p@home.test']);
    WorkspaceJoin::join($organization->fresh()->join_code, $pia);
    WorkspaceJoin::join($organization->fresh()->join_code, $longer);

    $vague = accessAgent($hr, 'decline_join_request', ['person' => 'Pia']);
    $exact = accessAgent($hr, 'decline_join_request', ['person' => 'pia  pending']);

    expect($vague->detail)->toContain('More than one pending request matches')
        ->and($exact->failed())->toBeFalse()
        ->and(OrganizationJoinRequest::query()->pending()->sole()->user_id)->toBe($longer->id);
});

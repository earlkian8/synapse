<?php

use App\Models\Employee;
use App\Models\OrganizationJoinRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\JoinRequestDecisionNotification;
use App\Support\OrganizationProvisioner;
use App\Support\WorkspaceJoin;
use Illuminate\Support\Facades\Notification;

/*
| Join codes (ADR 0026): the self-serve way into a company, with the one
| concession this being an HR system demands — a code alone admits you only when
| the roster already knows your email. Everyone else waits for HR.
*/

beforeEach(function () {
    Notification::fake();
    seedPermissions();
});

test('a matching roster email is admitted immediately and bound', function () {
    $organization = testOrganization();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    $employee = Employee::factory()->create(['email' => 'match@work.test', 'user_id' => null]);
    $person = User::factory()->unaffiliated()->create(['email' => 'match@work.test']);

    $result = WorkspaceJoin::join($organization->join_code, $person);

    expect($result['status'])->toBe(WorkspaceJoin::ADMITTED)
        ->and($employee->fresh()->user_id)->toBe($person->id)
        ->and($person->isMemberOf($organization))->toBeTrue()
        ->and($person->fresh()->roles()->pluck('name'))->toContain(Role::STAFF);
});

test('an unknown email is queued for HR instead of admitted', function () {
    $organization = testOrganization();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    $stranger = User::factory()->unaffiliated()->create(['email' => 'stranger@nowhere.test']);

    $result = WorkspaceJoin::join($organization->join_code, $stranger);

    expect($result['status'])->toBe(WorkspaceJoin::PENDING)
        ->and($stranger->isMemberOf($organization))->toBeFalse()
        ->and($stranger->memberships()->count())->toBe(0)
        ->and($result['request']->status)->toBe(OrganizationJoinRequest::PENDING);
});

test('an ambiguous email match is not guessed', function () {
    $organization = testOrganization();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    // Two roster lines share an address — binding either would be a coin toss.
    Employee::factory()->create(['email' => 'shared@work.test', 'user_id' => null]);
    Employee::factory()->create(['email' => 'shared@work.test', 'user_id' => null]);

    $person = User::factory()->unaffiliated()->create(['email' => 'shared@work.test']);

    expect(WorkspaceJoin::join($organization->join_code, $person)['status'])
        ->toBe(WorkspaceJoin::PENDING);
});

test('a roster line that already has an owner is not auto-matched', function () {
    $organization = testOrganization();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    Employee::factory()->create([
        'email' => 'taken@work.test',
        'user_id' => User::factory()->create()->id,
    ]);

    $imposter = User::factory()->unaffiliated()->create(['email' => 'taken@work.test']);

    expect(WorkspaceJoin::join($organization->join_code, $imposter)['status'])
        ->toBe(WorkspaceJoin::PENDING);
});

test('an unknown or disabled code is refused', function () {
    $organization = testOrganization();
    $organization->rotateJoinCode();
    $person = User::factory()->unaffiliated()->create();

    expect(fn () => WorkspaceJoin::join('ZZZZZZZ', $person))->toThrow(RuntimeException::class);

    $organization->update(['join_code_enabled' => false]);

    expect(fn () => WorkspaceJoin::join($organization->join_code, $person))
        ->toThrow(RuntimeException::class);
});

test('asking twice does not queue a second request', function () {
    $organization = testOrganization();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    $person = User::factory()->unaffiliated()->create(['email' => 'twice@nowhere.test']);
    WorkspaceJoin::join($organization->join_code, $person);

    expect(fn () => WorkspaceJoin::join($organization->join_code, $person))
        ->toThrow(RuntimeException::class);

    expect(OrganizationJoinRequest::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('an existing member cannot rejoin', function () {
    $organization = testOrganization();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    $person = User::factory()->create();
    OrganizationProvisioner::addMember($organization, $person, default: true);

    expect(fn () => WorkspaceJoin::join($organization->join_code, $person))
        ->toThrow(RuntimeException::class);
});

test('approving a request binds the roster line HR nominates', function () {
    $organization = testOrganization();
    $hr = actingAsSuperAdmin();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    $person = User::factory()->unaffiliated()->create(['email' => 'approve@nowhere.test']);
    $request = WorkspaceJoin::join($organization->join_code, $person)['request'];

    $employee = Employee::factory()->create(['email' => 'roster@work.test', 'user_id' => null]);

    WorkspaceJoin::approve($request, $employee, $hr);

    expect($request->fresh()->status)->toBe(OrganizationJoinRequest::APPROVED)
        ->and($employee->fresh()->user_id)->toBe($person->id)
        ->and($person->isMemberOf($organization))->toBeTrue();

    Notification::assertSentTo($person, JoinRequestDecisionNotification::class);
});

test('a claimed roster line cannot be handed to a second person', function () {
    $organization = testOrganization();
    $hr = actingAsSuperAdmin();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    $person = User::factory()->unaffiliated()->create(['email' => 'clash@nowhere.test']);
    $request = WorkspaceJoin::join($organization->join_code, $person)['request'];

    $taken = Employee::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(fn () => WorkspaceJoin::approve($request, $taken, $hr))
        ->toThrow(RuntimeException::class);
});

test('declining notifies the requester and blocks a second decision', function () {
    $organization = testOrganization();
    $hr = actingAsSuperAdmin();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    $person = User::factory()->unaffiliated()->create(['email' => 'decline@nowhere.test']);
    $request = WorkspaceJoin::join($organization->join_code, $person)['request'];

    WorkspaceJoin::decline($request, $hr, 'Not one of ours.');

    expect($request->fresh()->status)->toBe(OrganizationJoinRequest::DECLINED)
        ->and($request->fresh()->decline_reason)->toBe('Not one of ours.');

    Notification::assertSentTo($person, JoinRequestDecisionNotification::class);

    expect(fn () => WorkspaceJoin::decline($request->fresh(), $hr))
        ->toThrow(RuntimeException::class);
});

test('re-asking after a decline revives the same row', function () {
    $organization = testOrganization();
    $hr = actingAsSuperAdmin();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    $person = User::factory()->unaffiliated()->create(['email' => 'again@nowhere.test']);
    $request = WorkspaceJoin::join($organization->join_code, $person)['request'];
    WorkspaceJoin::decline($request, $hr);

    $second = WorkspaceJoin::join($organization->join_code, $person)['request'];

    expect($second->id)->toBe($request->id)
        ->and($second->status)->toBe(OrganizationJoinRequest::PENDING)
        ->and(OrganizationJoinRequest::query()->withoutGlobalScopes()->count())->toBe(1);
});

// ── HTTP surface ─────────────────────────────────────────────────────────────

test('the App Access screen lists requests, invitations and the backlog', function () {
    $organization = testOrganization();
    actingAsSuperAdmin();
    OrganizationProvisioner::provisionRoles($organization);
    $organization->rotateJoinCode();

    Employee::factory()->count(2)->create(['user_id' => null]);
    WorkspaceJoin::join(
        $organization->join_code,
        User::factory()->unaffiliated()->create(['email' => 'x@nowhere.test']),
    );

    $this->get(route('employees.access'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/access')
            ->has('requests', 1)
            ->has('unlinked', 2)
            ->where('joinCode.code', $organization->join_code)
            ->where('joinCode.enabled', true),
        );
});

test('the mobile API previews a code before committing to it', function () {
    $organization = testOrganization();
    $organization->rotateJoinCode();

    $person = User::factory()->unaffiliated()->create();
    $token = $person->createToken('mobile')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.workspaces.preview'), ['code' => strtolower($organization->join_code)])
        ->assertOk()
        ->assertJsonPath('organization.id', $organization->id)
        ->assertJsonPath('already_member', false);

    $this->withToken($token)
        ->postJson(route('api.workspaces.preview'), ['code' => 'ZZZZZZZ'])
        ->assertNotFound();
});

test('registering returns a real session with nowhere to stand', function () {
    $this->postJson(route('api.auth.register'), [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@personal.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])
        ->assertCreated()
        ->assertJsonPath('user.needs_workspace', true)
        ->assertJsonPath('user.organization', null)
        ->assertJsonPath('user.can_clock', false)
        ->assertJsonStructure(['token', 'user']);

    expect(User::where('email', 'ada@personal.test')->exists())->toBeTrue();
});

test('rotating the join code retires the old one', function () {
    $organization = testOrganization();
    actingAsSuperAdmin();
    $organization->rotateJoinCode();
    $old = $organization->join_code;

    $this->post(route('setup.company.join-code.rotate'))->assertRedirect();

    $fresh = $organization->fresh();

    expect($fresh->join_code)->not->toBe($old);

    $person = User::factory()->unaffiliated()->create();

    expect(fn () => WorkspaceJoin::join($old, $person))->toThrow(RuntimeException::class);
});

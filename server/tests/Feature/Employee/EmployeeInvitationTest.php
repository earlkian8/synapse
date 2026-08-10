<?php

use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmployeeInvitationNotification;
use App\Support\EmployeeInvitations;
use App\Support\JoinCode;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;

/*
| Invitations (ADR 0026): the targeted way into a company. HR points at a roster
| line, the person claims it with an account they registered themselves.
*/

beforeEach(function () {
    Notification::fake();
    seedPermissions();
});

// ── Issuing ──────────────────────────────────────────────────────────────────

test('inviting an employee emails a claim ticket and marks them invited', function () {
    $hr = actingAsSuperAdmin();
    $employee = Employee::factory()->create(['email' => 'nina@work.test', 'user_id' => null]);

    expect($employee->appAccess())->toBe('none');

    $invitation = EmployeeInvitations::invite($employee, $hr);

    expect($invitation->email)->toBe('nina@work.test')
        ->and($invitation->status())->toBe('pending')
        ->and($employee->fresh()->appAccess())->toBe('invited');

    Notification::assertSentTo($invitation, EmployeeInvitationNotification::class);
});

test('the link token is stored only as a hash', function () {
    $hr = actingAsSuperAdmin();
    $invitation = EmployeeInvitations::invite(
        Employee::factory()->create(['email' => 'hash@work.test', 'user_id' => null]),
        $hr,
    );

    // 64 hex characters is a sha256, not the 64 random characters that were sent.
    expect($invitation->token)->toMatch('/^[a-f0-9]{64}$/');
});

test('re-inviting supersedes the previous code', function () {
    $hr = actingAsSuperAdmin();
    $employee = Employee::factory()->create(['email' => 'again@work.test', 'user_id' => null]);

    $first = EmployeeInvitations::invite($employee, $hr);
    $second = EmployeeInvitations::invite($employee, $hr);

    expect($first->fresh()->status())->toBe('revoked')
        ->and($second->status())->toBe('pending')
        ->and(EmployeeInvitations::findByCode($first->code))->toBeNull()
        ->and(EmployeeInvitations::findByCode($second->code)?->id)->toBe($second->id);
});

test('an employee who already has access cannot be invited', function () {
    $hr = actingAsSuperAdmin();
    $employee = Employee::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(fn () => EmployeeInvitations::invite($employee, $hr))
        ->toThrow(RuntimeException::class);
});

test('an employee with no email cannot be invited', function () {
    $hr = actingAsSuperAdmin();
    $employee = Employee::factory()->create(['email' => null, 'user_id' => null]);

    expect(fn () => EmployeeInvitations::invite($employee, $hr))
        ->toThrow(RuntimeException::class);
});

// ── Redeeming ────────────────────────────────────────────────────────────────

test('accepting binds the roster line, grants staff, and joins the organisation', function () {
    $organization = testOrganization();
    $hr = actingAsSuperAdmin();
    OrganizationProvisioner::provisionRoles($organization);

    $employee = Employee::factory()->create(['email' => 'claim@work.test', 'user_id' => null]);
    $invitation = EmployeeInvitations::invite($employee, $hr);

    // Deliberately a different address: possession of the code is the authorisation.
    $person = User::factory()->create(['email' => 'personal@gmail.test']);

    EmployeeInvitations::accept($invitation, $person);

    expect($employee->fresh()->user_id)->toBe($person->id)
        ->and($person->isMemberOf($organization))->toBeTrue()
        ->and($person->fresh()->roles()->pluck('name'))->toContain(Role::STAFF)
        ->and($invitation->fresh()->status())->toBe('accepted')
        ->and($employee->fresh()->appAccess())->toBe('active');
});

test('a code cannot be redeemed twice', function () {
    $organization = testOrganization();
    $hr = actingAsSuperAdmin();
    OrganizationProvisioner::provisionRoles($organization);

    $invitation = EmployeeInvitations::invite(
        Employee::factory()->create(['email' => 'once@work.test', 'user_id' => null]),
        $hr,
    );

    EmployeeInvitations::accept($invitation, User::factory()->create());

    expect(fn () => EmployeeInvitations::accept($invitation->fresh(), User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

test('an expired invitation is not redeemable', function () {
    $hr = actingAsSuperAdmin();
    $invitation = EmployeeInvitations::invite(
        Employee::factory()->create(['email' => 'stale@work.test', 'user_id' => null]),
        $hr,
    );

    // Expiry is evaluated on read, never swept — so simply moving the clock is enough.
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    expect($invitation->fresh()->status())->toBe('expired')
        ->and(EmployeeInvitations::findByCode($invitation->code))->toBeNull();
});

test('a revoked invitation stops working', function () {
    $hr = actingAsSuperAdmin();
    $employee = Employee::factory()->create(['email' => 'gone@work.test', 'user_id' => null]);
    $invitation = EmployeeInvitations::invite($employee, $hr);

    expect(EmployeeInvitations::revoke($employee))->toBeTrue()
        ->and(EmployeeInvitations::findByCode($invitation->code))->toBeNull()
        ->and($employee->fresh()->appAccess())->toBe('none');
});

test('codes are found case-insensitively and past spacing', function () {
    $hr = actingAsSuperAdmin();
    $invitation = EmployeeInvitations::invite(
        Employee::factory()->create(['email' => 'messy@work.test', 'user_id' => null]),
        $hr,
    );

    $typed = ' '.strtolower(substr($invitation->code, 0, 4)).'-'.strtolower(substr($invitation->code, 4)).' ';

    expect(EmployeeInvitations::findByCode($typed)?->id)->toBe($invitation->id);
});

test('one identity may not hold two employee records at the same company', function () {
    $organization = testOrganization();
    $hr = actingAsSuperAdmin();
    OrganizationProvisioner::provisionRoles($organization);

    $person = User::factory()->create();

    $first = Employee::factory()->create(['email' => 'a@work.test', 'user_id' => null]);
    EmployeeInvitations::accept(EmployeeInvitations::invite($first, $hr), $person);

    $second = Employee::factory()->create(['email' => 'b@work.test', 'user_id' => null]);
    $invitation = EmployeeInvitations::invite($second, $hr);

    expect(fn () => EmployeeInvitations::accept($invitation, $person))
        ->toThrow(RuntimeException::class);
});

// ── Discovery ────────────────────────────────────────────────────────────────

test('listing only surfaces invitations addressed to the caller mailbox', function () {
    $hr = actingAsSuperAdmin();

    $mine = EmployeeInvitations::invite(
        Employee::factory()->create(['email' => 'Mine@Work.test', 'user_id' => null]),
        $hr,
    );
    EmployeeInvitations::invite(
        Employee::factory()->create(['email' => 'theirs@work.test', 'user_id' => null]),
        $hr,
    );

    // Matching is case-insensitive on both sides.
    $person = User::factory()->create(['email' => 'mine@work.test']);

    $found = EmployeeInvitations::for($person);

    expect($found)->toHaveCount(1)
        ->and($found->first()->id)->toBe($mine->id);
});

test('invitations are visible from outside the tenant that issued them', function () {
    $hr = actingAsSuperAdmin();
    $invitation = EmployeeInvitations::invite(
        Employee::factory()->create(['email' => 'outside@work.test', 'user_id' => null]),
        $hr,
    );

    $person = User::factory()->create(['email' => 'outside@work.test']);

    // Bind a *different* tenant, as a mobile token for another company would.
    $elsewhere = Organization::factory()->create();

    app(Tenancy::class)->runFor($elsewhere, function () use ($person, $invitation) {
        expect(EmployeeInvitations::for($person)->pluck('id'))->toContain($invitation->id)
            ->and(EmployeeInvitations::findByCode($invitation->code))->not->toBeNull();
    });
});

// ── HTTP surface ─────────────────────────────────────────────────────────────

test('the invite endpoint requires the employees.invite permission', function () {
    $user = User::factory()->create();
    $user->roles()->attach(makeRole('viewer', ['employees.view'])->id);
    OrganizationProvisioner::addMember(testOrganization(), $user, default: true);

    $employee = Employee::factory()->create(['email' => 'nope@work.test', 'user_id' => null]);

    $this->actingAs($user)
        ->post(route('employees.invite', $employee))
        ->assertForbidden();
});

test('HR can invite and revoke over HTTP', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create(['email' => 'http@work.test', 'user_id' => null]);

    $this->post(route('employees.invite', $employee))->assertRedirect();
    expect($employee->fresh()->appAccess())->toBe('invited');

    $this->delete(route('employees.invite.revoke', $employee))->assertRedirect();
    expect($employee->fresh()->appAccess())->toBe('none');
});

test('the public invitation page shows the company without revealing the token', function () {
    $hr = actingAsSuperAdmin();
    $employee = Employee::factory()->create(['email' => 'public@work.test', 'user_id' => null]);

    EmployeeInvitations::invite($employee, $hr);

    // The plain token only ever existed in the mail, so an unknown one renders the
    // same "expired" page rather than a 404 that would confirm what is real.
    $this->get(route('invite.show', ['token' => 'not-a-real-token']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invite')->where('invitation', null));
});

test('the mobile API accepts an invitation and returns a bound session', function () {
    $organization = testOrganization();
    OrganizationProvisioner::provisionRoles($organization);

    // Built without actingAs: Sanctum checks the `web` guard first, so a session
    // left behind by a test helper would answer the token requests below.
    $hr = User::factory()->create();
    $hr->roles()->attach(makeRole(Role::SUPER_ADMIN, [], true)->id);

    $employee = Employee::factory()->create(['email' => 'api@work.test', 'user_id' => null]);
    $invitation = EmployeeInvitations::invite($employee, $hr);

    $person = User::factory()->unaffiliated()->create();
    $token = $person->createToken('mobile')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.invitations.accept'), ['code' => $invitation->code])
        ->assertOk()
        ->assertJsonPath('user.organization.id', $organization->id)
        ->assertJsonPath('user.needs_workspace', false)
        ->assertJsonStructure(['token', 'message', 'user']);

    expect($employee->fresh()->user_id)->toBe($person->id);
});

// ── Codes ────────────────────────────────────────────────────────────────────

test('join codes never contain the characters people misread', function () {
    foreach (range(1, 40) as $ignored) {
        expect(JoinCode::generate(JoinCode::ORGANIZATION_LENGTH))
            ->not->toContain('I')
            ->not->toContain('L')
            ->not->toContain('O')
            ->not->toContain('U');
    }
});

test('typed codes fold the omitted letters onto their digits', function () {
    expect(JoinCode::normalize(' abc-o1i '))->toBe('ABC011')
        ->and(JoinCode::normalize('lOl'))->toBe('101')
        ->and(JoinCode::normalize(null))->toBe('');
});

test('every organisation is provisioned with a join code', function () {
    [$organization] = OrganizationProvisioner::create('Codes R Us');

    expect($organization->join_code)->toHaveLength(JoinCode::ORGANIZATION_LENGTH)
        ->and($organization->join_code_enabled)->toBeTrue()
        ->and(EmployeeInvitation::query()->withoutGlobalScopes()->count())->toBe(0);
});

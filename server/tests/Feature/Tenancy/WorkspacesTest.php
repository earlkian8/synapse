<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| One's own workspaces (ADR 0089): starting another company under the same
| sign-in, leaving one, and the rule that nobody walks out of a company and
| leaves its people without an HR Manager.
*/

/**
 * A second company with its built-in roles, which this person joins with the
 * given role (HR Manager by default).
 */
function wsSecondCompany(User $user, string $role = Role::HR_MANAGER, string $name = 'Second Co'): Organization
{
    $organization = Organization::factory()->create(['name' => $name]);
    OrganizationProvisioner::provisionRoles($organization);
    OrganizationProvisioner::admit($organization, $user, role: $role);

    return $organization;
}

/** A colleague in the company who can sign in. */
function wsColleague(Organization $organization, string $role = Role::STAFF): User
{
    $user = app(Tenancy::class)->runFor($organization, fn () => User::factory()->create());
    OrganizationProvisioner::admit($organization, $user, role: $role);

    return $user;
}

function wsNotified(User $user, string $title): bool
{
    return $user->notifications()->get()->contains(fn ($notification): bool => ($notification->data['title'] ?? null) === $title);
}

function wsRolesIn(User $user, Organization $organization): array
{
    return app(Tenancy::class)->runFor($organization, fn () => $user->roles()->pluck('name')->all());
}

// ── Creating ─────────────────────────────────────────────────────────────────

test('a signed-in person creates a workspace and becomes its HR Manager', function () {
    $home = testOrganization();
    $user = actingAsSuperAdmin();

    $this->post(route('workspaces.store'), ['name' => '  Northwind Trading '])
        ->assertRedirect(route('setup.wizard.show'));

    assertToast('success', 'Workspace created.');

    $created = Organization::query()->where('name', 'Northwind Trading')->sole();

    expect($user->isMemberOf($created))->toBeTrue()
        ->and(session('active_organization_id'))->toBe($created->id)
        ->and($created->join_code)->not->toBeNull()
        ->and($created->hasFinishedSetup())->toBeFalse()
        ->and(wsRolesIn($user, $created))->toBe([Role::HR_MANAGER])
        // Their sign-in still lands where it did.
        ->and($user->defaultOrganization()->is($home))->toBeTrue()
        ->and(Role::withoutGlobalScopes()->where('organization_id', $created->id)->count())->toBe(3)
        ->and(ActivityLog::withoutGlobalScopes()->where('organization_id', $created->id)->where('event', 'created')->exists())->toBeTrue();
});

test('anybody signed in may create one, not only an HR Manager', function () {
    actingAsUserWith([]);

    $this->post(route('workspaces.store'), ['name' => 'My Own Shop'])->assertRedirect(route('setup.wizard.show'));

    expect(Organization::query()->where('name', 'My Own Shop')->exists())->toBeTrue();
});

test('a workspace needs a name, and not one of their own again', function () {
    testOrganization()->update(['name' => 'Acme Corp']);
    actingAsSuperAdmin();

    $this->post(route('workspaces.store'), ['name' => ''])->assertSessionHasErrors('name');
    $this->post(route('workspaces.store'), ['name' => 'acme corp'])->assertSessionHasErrors(['name' => 'You already have a workspace with this name.']);

    expect(Organization::query()->count())->toBe(1);
});

test('registration still founds a workspace with its registrant as owner', function () {
    $this->post(route('register.store'), [
        'organization_name' => 'Registered Co',
        'first_name' => 'Rita',
        'last_name' => 'Reyes',
        'email' => 'rita@registered.test',
        'password' => 'Password123!secure',
        'password_confirmation' => 'Password123!secure',
    ])->assertRedirect();

    $user = User::query()->where('email', 'rita@registered.test')->sole();
    $organization = Organization::query()->where('name', 'Registered Co')->sole();

    expect($user->defaultOrganization()->is($organization))->toBeTrue()
        ->and(wsRolesIn($user, $organization))->toBe([Role::HR_MANAGER]);
});

// ── Leaving ──────────────────────────────────────────────────────────────────

test('leaving takes away that workspace only', function () {
    $home = testOrganization();
    $user = actingAsSuperAdmin();
    $second = wsSecondCompany($user, Role::STAFF);
    $hr = wsColleague($second, Role::HR_MANAGER);
    $user->memberships()->updateExistingPivot($home->id, ['is_default' => false]);
    $user->memberships()->updateExistingPivot($second->id, ['is_default' => true]);
    $user->createToken('phone')->accessToken->forceFill(['organization_id' => $second->id])->save();
    $user->createToken('phone')->accessToken->forceFill(['organization_id' => $home->id])->save();
    $employee = app(Tenancy::class)->runFor($second, fn () => Employee::factory()->create(['user_id' => $user->id]));

    $this->delete(route('workspaces.leave', $second))->assertRedirect(route('workspaces.manage'));
    assertToast('success', 'You left Second Co');

    expect($user->isMemberOf($second))->toBeFalse()
        ->and($user->isMemberOf($home))->toBeTrue()
        ->and(wsRolesIn($user, $second))->toBe([])
        ->and(wsRolesIn($user, $home))->toBe([Role::HR_MANAGER])
        ->and($user->tokens()->pluck('organization_id')->all())->toBe([$home->id])
        ->and($user->defaultOrganization()->is($home))->toBeTrue()
        // The employee record is the company's history and stays.
        ->and($employee->fresh()->user_id)->toBe($user->id)
        ->and(ActivityLog::withoutGlobalScopes()->where('organization_id', $second->id)->where('event', 'left')->exists())->toBeTrue()
        ->and(wsNotified($hr, "{$user->full_name} left the workspace"))->toBeTrue();
});

test('the only HR Manager may not leave while others work there', function () {
    actingAsSuperAdmin();
    $user = auth()->user();
    $second = wsSecondCompany($user);
    wsColleague($second);

    $this->delete(route('workspaces.leave', $second))->assertRedirect();
    assertToast('error', 'You are the only HR Manager of Second Co');

    expect($user->isMemberOf($second))->toBeTrue();
});

test('an HR Manager may leave once there is another', function () {
    $user = actingAsSuperAdmin();
    $second = wsSecondCompany($user);
    wsColleague($second);
    wsColleague($second, Role::HR_MANAGER);

    $this->delete(route('workspaces.leave', $second))->assertRedirect(route('workspaces.manage'));

    expect($user->isMemberOf($second))->toBeFalse();
});

test('a deactivated second HR Manager does not count', function () {
    $user = actingAsSuperAdmin();
    $second = wsSecondCompany($user);
    wsColleague($second);
    wsColleague($second, Role::HR_MANAGER)->update(['is_active' => false]);

    $this->delete(route('workspaces.leave', $second));
    assertToast('error');

    expect($user->isMemberOf($second))->toBeTrue();
});

test('leaving a workspace nobody else is in takes its name typed', function () {
    $user = actingAsSuperAdmin();
    $second = wsSecondCompany($user);

    $this->delete(route('workspaces.leave', $second), ['confirm_name' => 'wrong'])
        ->assertSessionHasErrors(['confirm_name' => 'Type Second Co to confirm.']);
    expect($user->isMemberOf($second))->toBeTrue();

    $this->delete(route('workspaces.leave', $second), ['confirm_name' => 'second co'])->assertRedirect(route('workspaces.manage'));
    expect($user->isMemberOf($second))->toBeFalse();
});

test('nobody leaves a workspace they are not in', function () {
    actingAsSuperAdmin();
    $other = Organization::factory()->create();

    $this->delete(route('workspaces.leave', $other))->assertNotFound();
});

test('leaving the workspace one is working in hands the session to the next', function () {
    $home = testOrganization();
    $user = actingAsSuperAdmin();
    $second = wsSecondCompany($user, Role::STAFF);
    wsColleague($second, Role::HR_MANAGER);

    $this->post(route('organization.switch'), ['organization_id' => $second->id]);
    $this->delete(route('workspaces.leave', $second));

    expect(session('active_organization_id'))->toBeNull();

    app(Tenancy::class)->forget();
    $this->get(route('workspaces.manage'))->assertOk();

    expect(app(Tenancy::class)->id())->toBe($home->id);
});

// ── Belonging to none ────────────────────────────────────────────────────────

test('leaving the last workspace lands on the picker, which offers a new one', function () {
    $organization = testOrganization();
    $user = actingAsSuperAdmin();

    $this->delete(route('workspaces.leave', $organization), ['confirm_name' => $organization->name])
        ->assertRedirect(route('workspaces'));

    expect($user->memberships()->count())->toBe(0);

    app(Tenancy::class)->forget();
    $this->get(route('workspaces'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspaces')->where('workspaces', []));
});

test('without a workspace no company page opens', function () {
    $user = User::factory()->create();
    $user->memberships()->detach();
    app(Tenancy::class)->forget();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('workspaces'));

    app(Tenancy::class)->forget();
    $this->actingAs($user)->getJson(route('search', ['q' => 'ana']))->assertForbidden();

    app(Tenancy::class)->forget();
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    app(Tenancy::class)->forget();
    $this->actingAs($user)->get(route('workspaces.manage'))->assertOk();
});

test('a workspace taken away mid-session says so and goes to the picker', function () {
    $home = testOrganization();
    $user = actingAsSuperAdmin();
    $second = wsSecondCompany($user, Role::STAFF);
    $this->post(route('organization.switch'), ['organization_id' => $second->id]);

    // Their HR removes them while they are working in it.
    $user->memberships()->detach($second->id);

    app(Tenancy::class)->forget();
    $this->get(route('employees.index'))->assertRedirect(route('workspaces'));
    assertToast('warning', 'You no longer have access to Second Co');

    expect(session('active_organization_id'))->toBe($home->id);
});

// ── Settings → Workspaces ────────────────────────────────────────────────────

test('settings lists every workspace and whether it can be left', function () {
    testOrganization()->update(['name' => 'Alpha']);
    $user = actingAsSuperAdmin();
    wsColleague(testOrganization());
    $second = wsSecondCompany($user, Role::STAFF, 'Beta');
    wsColleague($second, Role::HR_MANAGER);

    $this->get(route('workspaces.manage'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/workspaces')
            ->has('workspaces', 2)
            ->where('workspaces.0.name', 'Alpha')
            ->where('workspaces.0.is_active', true)
            ->where('workspaces.0.leaving.allowed', false)
            ->where('workspaces.1.name', 'Beta')
            ->where('workspaces.1.role', 'Staff')
            ->where('workspaces.1.leaving.allowed', true)
            ->where('workspaces.1.leaving.alone', false));
});

// ── Deleting one's account ───────────────────────────────────────────────────

test('the only HR Manager of a company with people in it cannot delete their account', function () {
    $user = actingAsSuperAdmin();
    $user->update(['password' => 'password']);
    wsColleague(testOrganization());

    $this->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('heldTogether', [testOrganization()->name]));

    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('account');

    expect($user->fresh()->trashed())->toBeFalse();
});

test('an owner alone in their company may still delete their account', function () {
    $user = actingAsSuperAdmin();
    $user->update(['password' => 'password']);

    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    expect($user->fresh()->trashed())->toBeTrue();
});

// ── Removed by the company ───────────────────────────────────────────────────

test('somebody removed from a workspace is told', function () {
    actingAsSuperAdmin();
    $person = wsColleague(testOrganization());
    wsSecondCompany($person, Role::STAFF);

    $this->delete(route('system.users.destroy', $person))->assertRedirect();

    expect($person->isMemberOf(testOrganization()))->toBeFalse()
        ->and($person->fresh()->trashed())->toBeFalse()
        ->and(wsNotified($person, 'You no longer have access to '.testOrganization()->name))->toBeTrue();
});

test('a finished exit shows that the leaver can still sign in, and who may end it', function () {
    $organization = testOrganization();
    $leaver = wsColleague($organization);
    $employee = Employee::factory()->create(['user_id' => $leaver->id]);
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'completed', 'completed_at' => now()]);

    actingAsSuperAdmin();
    $this->get(route('offboarding.show', $case))
        ->assertInertia(fn (Assert $page) => $page
            ->where('access.user_id', $leaver->id)
            ->where('access.shared', false)
            ->where('access.can_remove', true));

    actingAsUserWith(['offboarding.view']);
    $this->get(route('offboarding.show', $case))
        ->assertInertia(fn (Assert $page) => $page
            ->where('access.can_remove', false)
            ->where('access.reason', fn (string $reason) => Str::contains($reason, 'User Management')));

    $leaver->memberships()->detach($organization->id);
    $this->get(route('offboarding.show', $case))
        ->assertInertia(fn (Assert $page) => $page->where('access', null));
});

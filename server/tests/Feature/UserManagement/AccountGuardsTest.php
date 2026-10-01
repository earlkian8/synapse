<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| A user is an identity shared across workspaces (ADR 0023), and access is
| handed out by people who hold some of it. ADR 0057: an account another
| workspace shares is its holder's; nobody changes the account of someone with
| more access than they have; and nobody grants access they do not hold. Each
| test here pins an escalation that worked before.
*/

beforeEach(fn () => Notification::fake());

/**
 * Somebody whose account started in another company — where they are its HR
 * Manager — and who has since been added to this one.
 *
 * @return array{0: User, 1: Organization}
 */
function accountFromElsewhere(): array
{
    $home = testOrganization();
    $elsewhere = Organization::factory()->create();

    $user = app(Tenancy::class)->runFor($elsewhere, function () use ($elsewhere): User {
        $user = User::factory()->create(['first_name' => 'Elsa', 'middle_name' => null, 'last_name' => 'Where', 'suffix' => null, 'email' => 'elsa@elsewhere.test', 'password' => 'original-password']);
        $user->roles()->attach(OrganizationProvisioner::provisionRoles($elsewhere));

        return $user;
    });

    OrganizationProvisioner::addMember($home, $user);

    return [$user, $elsewhere];
}

test('a workspace cannot take over an account another workspace shares', function () {
    actingAsSuperAdmin();
    [$elsa, $elsewhere] = accountFromElsewhere();

    // Adding an address links the existing account in — and that is all it does.
    $this->put(route('system.users.password', $elsa), ['password' => 'Takeover-123!', 'password_confirmation' => 'Takeover-123!']);
    assertToast('error', 'also belongs to another workspace');

    $this->patch(route('system.users.update', $elsa), ['first_name' => 'Elsa', 'middle_name' => null, 'last_name' => 'Where', 'suffix' => null, 'email' => 'attacker@evil.test', 'is_active' => true]);
    assertToast('error', 'also belongs to another workspace');

    $this->patch(route('system.users.status', $elsa), ['is_active' => false]);
    assertToast('error', 'also belongs to another workspace');

    $this->delete(route('system.users.force-delete', $elsa->id));
    assertToast('error', 'cannot be deleted from here');

    $elsa->refresh();

    expect(Hash::check('original-password', $elsa->password))->toBeTrue()
        ->and($elsa->email)->toBe('elsa@elsewhere.test')
        ->and($elsa->is_active)->toBeTrue()
        ->and($elsa->trashed())->toBeFalse();
});

test('roles on a shared account can still change here', function () {
    actingAsSuperAdmin();
    [$elsa] = accountFromElsewhere();
    $auditor = makeRole('auditor', ['activity-logs.view']);

    $this->patch(route('system.users.update', $elsa), [...$elsa->only(['first_name', 'last_name', 'email']), 'is_active' => true, 'manage_roles' => true, 'roles' => [$auditor->id]]);

    assertToast('success', 'User updated.');
    expect($elsa->fresh()->roles->pluck('name')->all())->toBe(['auditor']);
});

test('archiving a shared account removes it from this workspace only', function () {
    actingAsSuperAdmin();
    [$elsa, $elsewhere] = accountFromElsewhere();
    $elsa->roles()->attach(makeRole('auditor', ['activity-logs.view']));
    $elsa->memberships()->updateExistingPivot(testOrganization()->id, ['is_default' => true]);
    $elsa->memberships()->updateExistingPivot($elsewhere->id, ['is_default' => false]);

    $this->delete(route('system.users.destroy', $elsa));

    assertToast('success', 'was removed from this organisation');

    $elsa->refresh();
    $rolesElsewhere = app(Tenancy::class)->runFor($elsewhere, fn () => $elsa->roles()->pluck('name')->all());

    expect($elsa->trashed())->toBeFalse()
        ->and($elsa->isMemberOf(testOrganization()))->toBeFalse()
        ->and($elsa->isMemberOf($elsewhere))->toBeTrue()
        ->and($elsa->defaultOrganization()?->id)->toBe($elsewhere->id)
        ->and($rolesElsewhere)->toBe([Role::SUPER_ADMIN])
        ->and(ActivityLog::query()->where('event', 'removed')->where('description', 'Removed user Elsa Where from the organisation')->exists())->toBeTrue();
});

test('nobody changes the account of someone with more access than they have', function () {
    testOrganization();
    $boss = User::factory()->create(['password' => 'boss-password']);
    $boss->roles()->attach(makeRole(Role::SUPER_ADMIN, [], true));
    $payroll = User::factory()->create();
    $payroll->roles()->attach(makeRole('payroll', ['users.view', 'employees.export']));

    actingAsUserWith(['users.view', 'users.update', 'users.reset-password', 'users.manage-status', 'users.delete']);

    $this->put(route('system.users.password', $boss), ['password' => 'Takeover-123!', 'password_confirmation' => 'Takeover-123!']);
    assertToast('error', 'has access you don\'t');

    $this->patch(route('system.users.status', $payroll), ['is_active' => false]);
    assertToast('error', 'has access you don\'t');

    $this->delete(route('system.users.destroy', $boss));
    assertToast('error', 'has access you don\'t');

    expect(Hash::check('boss-password', $boss->fresh()->password))->toBeTrue()
        ->and($payroll->fresh()->is_active)->toBeTrue()
        ->and($boss->fresh()->trashed())->toBeFalse();
});

test('whoever assigns roles cannot give access they do not hold', function () {
    $manager = makeRole(Role::SUPER_ADMIN, [], true);
    $payroll = makeRole('payroll', ['employees.export']);
    $viewer = makeRole('viewer', ['users.view']);
    $assigner = actingAsUserWith(['users.view', 'users.update', 'roles.assign']);
    $colleague = User::factory()->create();

    // Themselves an HR Manager — the whole system — was one form away.
    $this->patch(route('system.users.update', $assigner), [...$assigner->only(['first_name', 'last_name', 'email']), 'is_active' => true, 'manage_roles' => true, 'roles' => [...$assigner->roles->pluck('id'), $manager->id]]);
    assertToast('error', 'Only an');

    $this->post(route('system.users.bulk'), ['action' => 'assign-role', 'ids' => [$colleague->id], 'role_id' => $payroll->id]);
    assertToast('error', 'grants access you don\'t have yourself');

    $this->post(route('system.users.bulk'), ['action' => 'assign-role', 'ids' => [$colleague->id], 'role_id' => $viewer->id]);
    assertToast('success', 'Role assigned to 1 user.');

    expect($assigner->fresh()->isSuperAdmin())->toBeFalse()
        ->and($colleague->fresh()->roles->pluck('name')->all())->toBe(['viewer']);
});

test('a role editor adds only permissions they hold, and keeps what they could not have given', function () {
    $role = makeRole('clerk', ['employees.export', 'users.view']);
    actingAsUserWith(['roles.view', 'roles.update', 'roles.create', 'users.view']);

    $this->patch(route('system.roles.update', $role), ['label' => 'Clerk', 'permissions' => ['employees.export', 'users.view', 'roles.assign']]);
    assertToast('error', 'You can only grant access you have yourself');

    // Unchanged permissions the editor lacks may stay — or go.
    $this->patch(route('system.roles.update', $role), ['label' => 'Clerk', 'permissions' => ['employees.export', 'roles.view']]);
    assertToast('success', 'Role updated.');

    $this->post(route('system.roles.store'), ['label' => 'Everything', 'permissions' => ['roles.view', 'leave.manage']]);
    assertToast('error', 'Approve / reject leave & set balances');

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['employees.export', 'roles.view'])
        ->and(Role::query()->where('label', 'Everything')->exists())->toBeFalse();
});

test('the workspace keeps an HR Manager, and only an HR Manager makes another', function () {
    $owner = actingAsSuperAdmin();
    $manager = Role::query()->where('name', Role::SUPER_ADMIN)->sole();

    $this->patch(route('system.users.update', $owner), [...$owner->only(['first_name', 'last_name', 'email']), 'is_active' => true, 'manage_roles' => true, 'roles' => []]);

    assertToast('error', "the workspace's only active");
    expect($owner->fresh()->isSuperAdmin())->toBeTrue()
        ->and($manager->users()->count())->toBe(1);
});

test('a role key is unique within a workspace, not across all of them', function () {
    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => Role::create(['name' => 'auditor', 'label' => 'Auditor']));
    actingAsSuperAdmin();

    $this->post(route('system.roles.store'), ['label' => 'Auditor'])->assertSessionHasNoErrors();
    $this->post(route('system.roles.store'), ['label' => 'Auditor'])->assertSessionHasErrors('name');
    $this->post(route('system.roles.store'), ['label' => 'AUDITOR', 'name' => 'auditor-2']);

    assertToast('error', 'There is already a role called');
    expect(Role::query()->where('name', 'auditor')->count())->toBe(1);
});

test('the importer cannot give a role its user could not give by hand', function () {
    makeRole(Role::SUPER_ADMIN, [], true);
    actingAsUserWith(['users.view', 'users.create', 'roles.assign']);

    $csv = UploadedFile::fake()->createWithContent('users.csv', "first_name,last_name,email,role\nIva,Nuevo,iva@example.com,HR Manager\n");

    $this->post(route('system.users.import.store'), ['file' => $csv])
        ->assertOk()
        ->assertJsonPath('created', 0)
        ->assertJsonPath('errors.0.messages.0', fn (string $message): bool => str_starts_with($message, 'Only an') && str_contains($message, 'can make somebody an'));
});

test('a deactivated account is signed out and cannot sign back in', function () {
    testOrganization();
    $person = User::factory()->create(['password' => 'secret-password']);
    $token = $person->createToken('phone')->plainTextToken;

    actingAsSuperAdmin();
    $this->patch(route('system.users.status', $person), ['is_active' => false]);
    assertToast('success', 'User deactivated.');

    expect($person->tokens()->count())->toBe(0);

    // A session opened before the change ends on its next request.
    $this->actingAs($person->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest('web');

    $this->post(route('login.store'), ['email' => $person->email, 'password' => 'secret-password'])
        ->assertSessionHasErrors(['email' => 'This account is inactive.']);
    $this->assertGuest('web');

    $this->post(route('login.store'), ['email' => $person->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    expect($token)->not->toBe('');
});

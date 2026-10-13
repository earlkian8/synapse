<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy;

/*
| The portal's monitoring (ADR 0085): every organisation and ERP account, across
| tenants, read-only, and without the secrets the ERP keeps.
*/

/**
 * Two companies: Alpha (two members, three employees, an HR Manager) and Beta
 * (one member, one employee, still in setup), plus an account in neither.
 *
 * @return array{alpha: Organization, beta: Organization, owner: User}
 */
function monitoredPlatform(): array
{
    $tenancy = app(Tenancy::class);
    $alpha = Organization::factory()->create(['name' => 'Alpha Foods', 'join_code' => 'ALPHA-SECRET']);
    $beta = Organization::factory()->newlyRegistered()->create(['name' => 'Beta Builders']);

    $owner = $tenancy->runFor($alpha, function () use ($alpha): User {
        $owner = User::factory()->create(['first_name' => 'Lara', 'last_name' => 'Quinto', 'middle_name' => null, 'suffix' => null]);
        User::factory()->create(['is_active' => false]);
        $role = Role::create(['organization_id' => $alpha->id, 'name' => Role::SUPER_ADMIN, 'label' => 'HR Manager']);
        $owner->roles()->attach($role);
        Employee::factory()->count(3)->create(['organization_id' => $alpha->id]);
        ActivityLog::create(['organization_id' => $alpha->id, 'event' => 'created', 'description' => 'Hired someone']);

        return $owner;
    });

    $tenancy->runFor($beta, function () use ($beta): void {
        User::factory()->create(['email_verified_at' => null]);
        Employee::factory()->create(['organization_id' => $beta->id]);
        // Made under a bound tenant so the factory's membership (then undone)
        // does not invent a third company.
        User::factory()->unaffiliated()->create(['last_login_at' => now()]);
    });

    $tenancy->forget();

    return ['alpha' => $alpha, 'beta' => $beta, 'owner' => $owner];
}

it('counts the whole platform on the overview', function () {
    monitoredPlatform();

    $this->getJson('/api/admin/overview', asPortalAdmin())
        ->assertOk()
        ->assertJsonPath('stats.organizations', 2)
        ->assertJsonPath('stats.in_setup', 1)
        ->assertJsonPath('stats.accounts', 4)
        ->assertJsonPath('stats.unverified', 1)
        ->assertJsonPath('stats.employees', 4)
        ->assertJsonPath('stats.active_30d', 1)
        ->assertJsonCount(2, 'newest_organizations')
        ->assertJsonCount(1, 'recent_sign_ins');
});

it('shows recent admin activity on the overview to super admins only', function () {
    AdminActivityLog::create(['event' => 'sign_in', 'description' => 'Signed in']);

    $this->getJson('/api/admin/overview', asPortalAdmin())->assertJsonCount(1, 'recent_activity');
    $this->getJson('/api/admin/overview', asPortalAdmin(Admin::factory()->create()))->assertJsonPath('recent_activity', null);
});

it('lists organisations with their measures', function () {
    ['alpha' => $alpha] = monitoredPlatform();

    $response = $this->getJson('/api/admin/organizations?sort=name&direction=asc', asPortalAdmin())
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('stats.in_setup', 1)
        ->assertJsonPath('data.0.name', 'Alpha Foods')
        ->assertJsonPath('data.0.members_count', 2)
        ->assertJsonPath('data.0.employees_count', 3)
        ->assertJsonPath('data.1.setup_completed_at', null);

    expect($response->json('data.0.last_activity_at'))->not->toBeNull()
        ->and($response->json('data.0'))->not->toHaveKey('join_code');

    $this->getJson('/api/admin/organizations?status=setup', asPortalAdmin())->assertJsonPath('data.0.name', 'Beta Builders');
    $this->getJson('/api/admin/organizations?search=alpha', asPortalAdmin())->assertJsonPath('meta.total', 1);
    $this->getJson('/api/admin/organizations?sort=employees_count&direction=desc', asPortalAdmin())->assertJsonPath('data.0.id', $alpha->id);
});

it('opens an organisation, archived or not, without its secrets', function () {
    ['alpha' => $alpha] = monitoredPlatform();
    $alpha->delete();

    $response = $this->getJson("/api/admin/organizations/{$alpha->id}", asPortalAdmin())
        ->assertOk()
        ->assertJsonPath('data.name', 'Alpha Foods')
        ->assertJsonPath('data.owners.0.full_name', 'Lara Quinto')
        ->assertJsonPath('data.activity_30d', 1)
        ->assertJsonCount(2, 'data.members');

    expect($response->json('data.archived_at'))->not->toBeNull();

    expect($response->getContent())->not->toContain('ALPHA-SECRET')
        ->and($response->json('data'))->not->toHaveKeys(['join_code', 'tin', 'sss_employer_no', 'philhealth_employer_no', 'pagibig_employer_no'])
        ->and($response->json('data.members.0'))->not->toHaveKey('password');

    $this->getJson('/api/admin/organizations?status=archived', asPortalAdmin())->assertJsonPath('meta.total', 1);
    $this->getJson('/api/admin/organizations/999999', asPortalAdmin())->assertNotFound();
});

it('lists accounts across organisations', function () {
    monitoredPlatform();
    $headers = asPortalAdmin();

    $this->getJson('/api/admin/accounts', $headers)
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('stats.inactive', 1)
        ->assertJsonPath('stats.no_workspace', 1);

    $this->getJson('/api/admin/accounts?search=lara%20quinto', $headers)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.full_name', 'Lara Quinto')
        ->assertJsonMissingPath('data.0.password');

    $this->getJson('/api/admin/accounts?status=unverified', $headers)->assertJsonPath('meta.total', 1);
    $this->getJson('/api/admin/accounts?status=no_workspace', $headers)->assertJsonPath('data.0.memberships_count', 0);
});

it('opens an account with its memberships and roles', function () {
    ['owner' => $owner] = monitoredPlatform();
    $owner->createToken('mobile');

    $response = $this->getJson("/api/admin/accounts/{$owner->id}", asPortalAdmin())
        ->assertOk()
        ->assertJsonPath('data.memberships.0.name', 'Alpha Foods')
        ->assertJsonPath('data.memberships.0.roles.0', 'HR Manager')
        ->assertJsonPath('data.mobile_sessions', 1);

    foreach (['two_factor_secret', 'remember_token', '"password"'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

it('falls back to sane defaults for odd filters', function () {
    monitoredPlatform();

    $this->getJson('/api/admin/accounts?per_page=1000&sort=password&status=everything', asPortalAdmin())
        ->assertOk()
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('filters.sort', 'created_at')
        ->assertJsonPath('filters.status', 'all');
});

it('keeps an admin with an issued password away from the monitoring', function () {
    $headers = asPortalAdmin(Admin::factory()->create(['must_change_password' => true]));

    $this->getJson('/api/admin/organizations', $headers)->assertForbidden();
    $this->getJson('/api/admin/accounts', $headers)->assertForbidden();
});

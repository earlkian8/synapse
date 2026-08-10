<?php

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionSyncer;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| RBAC test helpers
|--------------------------------------------------------------------------
|
| Routes are permission-gated, so feature tests must authenticate as a user
| that actually holds the required permissions. These helpers seed the
| permission catalogue and build roles/users on demand.
|
*/

function seedPermissions(): void
{
    PermissionSyncer::sync();
}

/**
 * The organisation bound as the current tenant for this test, creating and binding
 * one on first use. Keeps every factory-built record (and the acting user) in the
 * same tenant so scoped queries behave as they would in a real request.
 */
function testOrganization(): Organization
{
    $tenancy = app(Tenancy::class);

    if (! $tenancy->check()) {
        $tenancy->set(Organization::factory()->create());
    }

    return $tenancy->organization();
}

/**
 * Create a role (in the current test tenant) granting the given permission names.
 *
 * @param  list<string>  $permissions
 */
function makeRole(string $name, array $permissions = [], bool $system = false): Role
{
    seedPermissions();
    testOrganization();

    $role = Role::firstOrCreate(
        ['name' => $name],
        ['label' => ucwords(str_replace('-', ' ', $name)), 'is_system' => $system],
    );

    $role->permissions()->sync(
        Permission::whereIn('name', $permissions)->pluck('id')
    );

    return $role;
}

/**
 * Authenticate as a Super Admin (bypasses every gate) and return the user.
 */
function actingAsSuperAdmin(): User
{
    $role = makeRole(Role::SUPER_ADMIN, [], true);
    $user = User::factory()->create();
    $user->roles()->attach($role);

    test()->actingAs($user);

    return $user;
}

/**
 * Authenticate as a user holding exactly the given permissions.
 *
 * @param  list<string>  $permissions
 */
function actingAsUserWith(array $permissions): User
{
    $role = makeRole('test-role-'.Str::random(8), $permissions);
    $user = User::factory()->create();
    $user->roles()->attach($role);

    test()->actingAs($user);

    return $user;
}

/**
 * Assert the last response flashed a toast of the given level, optionally
 * containing a fragment of copy.
 *
 * Toasts are the app's feedback contract: the server flashes
 * `Inertia::flash('toast', ['type' => …, 'message' => …])`, which lands in the
 * session under `inertia.flash_data` and is rendered by `use-flash-toast.ts`.
 * Tests assert it here rather than reaching for that key by hand, so the whole
 * suite moves together if the transport ever changes.
 *
 * @param  'success'|'error'|'warning'|'info'  $type
 */
function assertToast(string $type, ?string $contains = null): void
{
    $toast = session('inertia.flash_data.toast');

    expect($toast)->not->toBeNull('No toast was flashed.')
        ->and($toast['type'] ?? null)->toBe($type);

    if ($contains !== null) {
        expect($toast['message'] ?? '')->toContain($contains);
    }
}

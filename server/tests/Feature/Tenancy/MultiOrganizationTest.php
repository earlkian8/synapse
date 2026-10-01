<?php

use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Support\EmployeeInvitations;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Inertia\Testing\AssertableInertia as Assert;

/*
| One identity, many organisations (ADR 0023). A user can belong to several
| companies and switch the active one; every switch must stay confined to the
| user's memberships so tenant isolation holds.
*/

test('switching organisation rebinds the tenant for web requests', function () {
    seedPermissions();

    // Org A: the acting user is a member + super admin; it has 1 employee.
    $user = actingAsSuperAdmin();
    Employee::factory()->create();

    // Org B: same identity is a member + super admin; it has 3 employees.
    $orgB = Organization::factory()->create();
    $superB = OrganizationProvisioner::provisionRoles($orgB);
    OrganizationProvisioner::addMember($orgB, $user);
    $user->roles()->syncWithoutDetaching([$superB->id]);
    app(Tenancy::class)->runFor($orgB, fn () => Employee::factory()->count(3)->create());

    // Default lands in org A.
    $this->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));

    // After switching, the same listing shows org B's employees.
    $this->post(route('organization.switch'), ['organization_id' => $orgB->id])
        ->assertRedirect();

    $this->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 3));
});

test('a user cannot switch to an organisation they do not belong to', function () {
    actingAsSuperAdmin();                 // member of org A only
    Employee::factory()->count(2)->create();

    $orgB = Organization::factory()->create();
    app(Tenancy::class)->runFor($orgB, fn () => Employee::factory()->count(5)->create());

    // The switch is rejected (not a member), so the tenant stays org A.
    $this->post(route('organization.switch'), ['organization_id' => $orgB->id]);

    $this->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 2));
});

test('one identity can accept invitations from two organisations', function () {
    // Since ADR 0026 nothing creates a login on the employer's behalf — the person
    // registers once and claims a roster line at each company that invites them.
    seedPermissions();

    $orgA = testOrganization();
    $hrA = actingAsSuperAdmin();
    $person = User::factory()->create(['email' => 'jordan@dual.test']);

    $employeeA = Employee::factory()->create(['email' => 'jordan@dual.test', 'user_id' => null]);
    EmployeeInvitations::accept(EmployeeInvitations::invite($employeeA, $hrA), $person);

    expect($person->isMemberOf($orgA))->toBeTrue()
        ->and($employeeA->fresh()->user_id)->toBe($person->id);

    // A second company invites the same human. One identity, two memberships.
    $orgB = Organization::factory()->create();
    OrganizationProvisioner::provisionRoles($orgB);

    app(Tenancy::class)->runFor($orgB, function () use ($hrA, $person) {
        $employeeB = Employee::factory()->create(['email' => 'jordan@dual.test', 'user_id' => null]);

        EmployeeInvitations::accept(EmployeeInvitations::invite($employeeB, $hrA), $person);

        expect($employeeB->fresh()->user_id)->toBe($person->id);
    });

    expect($person->isMemberOf($orgB))->toBeTrue()
        ->and($person->memberships()->count())->toBe(2)
        ->and(User::where('email', 'jordan@dual.test')->count())->toBe(1);
});

test('a token bound to one organisation cannot read another tenant', function () {
    seedPermissions();

    // Two organisations, each with an employee; the user belongs only to org A.
    $user = actingAsSuperAdmin();
    $orgA = testOrganization();

    // The mobile endpoints are self-scoped — they answer for the caller's own
    // employee record — so the acting user needs one, in org A.
    app(Tenancy::class)->runFor($orgA, fn () => Employee::factory()->create(['user_id' => $user->id]));

    $orgB = Organization::factory()->create();
    app(Tenancy::class)->runFor($orgB, fn () => Employee::factory()->count(4)->create());

    // A token bound to org A only ever resolves org A's rows.
    $token = $user->createToken('test');
    $token->accessToken->forceFill(['organization_id' => $orgA->id])->save();

    $this->withToken($token->plainTextToken)
        ->getJson(route('api.attendance.summary'))
        ->assertOk();

    // The employee directory (web) under org A shows exactly its one employee —
    // org B's four are not merely filtered out of the page, they are unreachable.
    $this->get(route('employees.index'))
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));

    expect(Employee::query()->count())->toBe(1)
        ->and(Employee::withoutGlobalScopes()->count())->toBe(5);
});

test('a self-scoped endpoint refuses a user with no employee record', function () {
    seedPermissions();

    // No roster line means nothing to answer about — and 403 rather than an
    // empty 200, so the client cannot mistake "not linked" for "no data".
    $user = actingAsSuperAdmin();
    $token = $user->createToken('test');
    $token->accessToken->forceFill(['organization_id' => testOrganization()->id])->save();

    $this->withToken($token->plainTextToken)
        ->getJson(route('api.attendance.summary'))
        ->assertForbidden();
});

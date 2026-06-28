<?php

use App\Models\Employee;
use App\Models\Organization;
use App\Support\EmployeeAccountProvisioner;
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

test('hiring an applicant with an existing email links the identity into the new organisation', function () {
    // Identity first created when hired by org A.
    $orgA = testOrganization();
    $employeeA = Employee::factory()->create(['email' => 'jordan@dual.test', 'user_id' => null]);
    [$userA, $passwordA] = EmployeeAccountProvisioner::provision($employeeA);

    expect($userA->isMemberOf($orgA))->toBeTrue()
        ->and($passwordA)->not->toBeNull();   // a brand-new account got a temp password

    // Later hired by org B with the same email → the same identity is reused.
    $orgB = Organization::factory()->create();
    [$userB, $passwordB] = app(Tenancy::class)->runFor($orgB, function () {
        $employeeB = Employee::factory()->create(['email' => 'jordan@dual.test', 'user_id' => null]);

        return EmployeeAccountProvisioner::provision($employeeB);
    });

    expect($userB->id)->toBe($userA->id)        // one identity, not two accounts
        ->and($passwordB)->toBeNull()           // existing account → no new password/email
        ->and($userB->isMemberOf($orgB))->toBeTrue()
        ->and($userB->memberships()->count())->toBe(2);
});

test('a token bound to one organisation cannot read another tenant', function () {
    seedPermissions();

    // Two organisations, each with an employee; the user belongs only to org A.
    $user = actingAsSuperAdmin();
    $orgA = testOrganization();
    app(Tenancy::class)->runFor($orgA, fn () => Employee::factory()->create());

    $orgB = Organization::factory()->create();
    app(Tenancy::class)->runFor($orgB, fn () => Employee::factory()->count(4)->create());

    // A token bound to org A only ever resolves org A's rows.
    $token = $user->createToken('test');
    $token->accessToken->forceFill(['organization_id' => $orgA->id])->save();

    $this->withToken($token->plainTextToken)
        ->getJson(route('api.attendance.summary'))
        ->assertOk();

    // The employee directory (web) under org A shows exactly its one employee.
    $this->get(route('employees.index'))
        ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));
});

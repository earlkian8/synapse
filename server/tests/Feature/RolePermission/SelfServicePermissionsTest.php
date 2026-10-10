<?php

use App\Models\Organization;
use App\Models\Role;
use App\Support\OrganizationProvisioner;
use App\Support\PermissionRegistry;
use App\Support\PermissionSyncer;
use App\Support\Tenancy;

/*
| Self-service permissions every employee holds (ADR 0070, 0071): answering one's
| own event invitations, and taking part in recognition. New companies get them
| on their built-in roles; existing companies get them from the migration, through
| PermissionSyncer::grant().
*/

test('the self-service permissions are catalogued', function () {
    expect(PermissionRegistry::names())->toContain('events.respond', 'awards.participate');
});

test('a new company’s Staff and Department Head roles can answer invitations and take part in recognition', function () {
    seedPermissions();
    $organization = Organization::factory()->create();
    app(Tenancy::class)->set($organization);
    OrganizationProvisioner::provisionRoles($organization);

    foreach ([Role::STAFF, Role::DEPARTMENT_HEAD, Role::HR_MANAGER] as $name) {
        $granted = Role::query()->where('name', $name)->firstOrFail()->permissions()->pluck('name');

        expect($granted)->toContain('events.respond', 'awards.participate');
    }
});

test('grant gives a permission to the named built-in roles of every company, once, and leaves other roles alone', function () {
    seedPermissions();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();

    $roles = collect([$first, $second])->flatMap(function (Organization $organization): array {
        app(Tenancy::class)->set($organization);

        return [
            makeRole(Role::STAFF, ['attendance.clock'], true),
            makeRole('night-crew', ['attendance.clock']),
        ];
    });

    app(Tenancy::class)->forget();

    PermissionSyncer::grant(['events.respond'], [Role::STAFF]);
    PermissionSyncer::grant(['events.respond'], [Role::STAFF]);

    foreach ($roles as $role) {
        $names = $role->permissions()->pluck('name');

        expect($names->contains('events.respond'))->toBe($role->name === Role::STAFF)
            ->and($names->filter(fn (string $name): bool => $name === 'events.respond')->count())->toBeLessThanOrEqual(1)
            ->and($names)->toContain('attendance.clock');
    }
});

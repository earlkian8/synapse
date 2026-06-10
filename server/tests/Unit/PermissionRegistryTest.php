<?php

use App\Support\PermissionRegistry;
use Tests\TestCase;

uses(TestCase::class);

test('names returns a flat, unique list of every permission', function () {
    $names = PermissionRegistry::names();

    expect($names)
        ->toContain('users.view')
        ->toContain('roles.assign')
        ->toContain('activity-logs.delete')
        ->and($names)->toBe(array_values(array_unique($names)));
});

test('groups returns name/label pairs nested under their group', function () {
    $groups = PermissionRegistry::groups();

    expect($groups)->each->toHaveKeys(['group', 'permissions']);

    $userGroup = collect($groups)->firstWhere('group', 'User Management');

    expect($userGroup)->not->toBeNull()
        ->and($userGroup['permissions'])->each->toHaveKeys(['name', 'label']);
});

test('every catalogued permission resolves back to its group', function () {
    foreach (PermissionRegistry::names() as $name) {
        expect(PermissionRegistry::groupFor($name))->not->toBeNull();
    }
});

test('groupFor returns null for an unknown permission', function () {
    expect(PermissionRegistry::groupFor('does.not.exist'))->toBeNull();
});

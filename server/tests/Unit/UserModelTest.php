<?php

use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

test('full name combines the name parts and suffix', function () {
    $user = new User([
        'first_name' => 'Jane',
        'middle_name' => 'Q',
        'last_name' => 'Doe',
        'suffix' => 'Jr.',
    ]);

    expect($user->full_name)->toBe('Jane Q Doe Jr.');
});

test('full name omits an empty middle name and suffix', function () {
    $user = new User([
        'first_name' => 'John',
        'last_name' => 'Smith',
    ]);

    expect($user->full_name)->toBe('John Smith');
});

test('is_active is cast to a boolean', function () {
    $user = new User(['is_active' => 1]);
    expect($user->is_active)->toBeTrue();

    $user->is_active = 0;
    expect($user->is_active)->toBeFalse();
});

test('full_name is appended to the serialized model', function () {
    $user = new User([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);

    expect($user->toArray())->toHaveKey('full_name', 'Ada Lovelace');
});

<?php

use App\Models\Employee;

test('full_name combines the name parts and suffix', function () {
    $employee = new Employee([
        'first_name' => 'Maria',
        'middle_name' => 'Reyes',
        'last_name' => 'Santos',
        'suffix' => 'Jr.',
    ]);

    expect($employee->full_name)->toBe('Maria Reyes Santos Jr.');
});

test('full_name skips missing parts', function () {
    $employee = new Employee([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
    ]);

    expect($employee->full_name)->toBe('Juan Dela Cruz');
});

test('initials use the first and last name', function () {
    $employee = new Employee([
        'first_name' => 'Maria',
        'last_name' => 'Santos',
    ]);

    expect($employee->initials())->toBe('MS');
});

<?php

use App\Models\Applicant;

test('it builds an applicant full name', function () {
    $applicant = new Applicant(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($applicant->full_name)->toBe('Ada Lovelace');
});

test('it builds applicant initials', function () {
    $applicant = new Applicant(['first_name' => 'Grace', 'last_name' => 'Hopper']);

    expect($applicant->initials())->toBe('GH');
});

<?php

use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    // Registering provisions a whole tenant, so the company name is required —
    // see App\Actions\Fortify\CreateNewUser and ADR 0005.
    $response = $this->post(route('register.store'), [
        'organization_name' => 'Test Company',
        'first_name' => 'Test',
        'middle_name' => 'Q',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('workspaces', absolute: false));
});

test('registration without a company name is rejected', function () {
    $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'nocompany@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('organization_name');

    $this->assertGuest();
});

<?php

use App\Models\User;

// `/` has no page of its own (ADR 0084): the landing site is the front door.

test('a guest opening the root is sent to sign in', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('somebody signed in opening the root goes where sign-in lands', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(config('fortify.home'));
});

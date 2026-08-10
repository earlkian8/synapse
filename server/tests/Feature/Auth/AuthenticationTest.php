<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();

    // Login lands on the workspace picker, because one identity may belong to
    // several companies (ADR 0023) — `fortify.home` is `/workspaces`.
    $response->assertRedirect(route('workspaces', absolute: false));

    // Somebody with a single membership has nothing to pick, so the picker drops
    // them straight into their dashboard.
    $this->get(route('workspaces'))->assertRedirect(route('dashboard'));
});

test('a user in more than one company is asked which to work in', function () {
    $user = User::factory()->create();
    $second = Organization::factory()->create();
    OrganizationProvisioner::addMember($second, $user);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('workspaces', absolute: false));

    // Two memberships means a real choice: the picker renders instead of
    // guessing which company they meant.
    $this->get(route('workspaces'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces')
            ->has('workspaces', 2)
        );
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

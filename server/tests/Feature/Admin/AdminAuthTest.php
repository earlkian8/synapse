<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;
use Illuminate\Testing\TestResponse;

/*
| Signing in to the admin portal (ADR 0085): a password, then always an
| authenticator code, then a short, idle-limited session.
*/

function portalLogin(string $email, string $password = 'Correct-Horse-9'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/admin/auth/login', ['email' => $email, 'password' => $password]);
}

function portalTwoFactor(string $challenge, string $code): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/admin/auth/two-factor', ['challenge' => $challenge, 'code' => $code]);
}

it('signs an enrolled admin in with a password and a code', function () {
    $admin = Admin::factory()->create(['email' => 'rhea@synapse.ph']);

    $login = portalLogin('Rhea@Synapse.ph')
        ->assertOk()
        ->assertJsonPath('status', 'two_factor')
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('setup');

    $session = portalTwoFactor($login->json('challenge'), portalCode($admin->two_factor_secret))
        ->assertOk()
        ->assertJsonPath('admin.email', 'rhea@synapse.ph')
        ->assertJsonPath('idle_minutes', 30);

    $this->getJson('/api/admin/me', portalHeaders($session->json('token')))->assertOk();

    expect($admin->refresh()->last_login_at)->not->toBeNull()
        ->and(AdminActivityLog::query()->where('event', 'sign_in')->where('admin_id', $admin->id)->exists())->toBeTrue();
});

it('sets up the authenticator at a first sign-in', function () {
    $admin = Admin::factory()->withoutTwoFactor()->create();

    $login = portalLogin($admin->email)
        ->assertOk()
        ->assertJsonPath('status', 'two_factor_setup');

    $secret = $login->json('setup.secret');
    expect($login->json('setup.otpauth_url'))->toStartWith('otpauth://totp/')
        ->and($admin->refresh()->two_factor_secret)->toBeNull();

    portalTwoFactor($login->json('challenge'), portalCode($secret))->assertOk();

    $admin->refresh();
    expect($admin->hasTwoFactor())->toBeTrue()
        ->and($admin->two_factor_secret)->toBe($secret)
        ->and(AdminActivityLog::query()->where('event', 'two_factor_enrolled')->exists())->toBeTrue();
});

it('answers a wrong password and an unknown email the same way', function () {
    Admin::factory()->create(['email' => 'rhea@synapse.ph']);

    $wrong = portalLogin('rhea@synapse.ph', 'Not-The-Password-1')->assertUnprocessable();
    $unknown = portalLogin('nobody@synapse.ph')->assertUnprocessable();

    expect($wrong->json('errors.email'))->toBe($unknown->json('errors.email'))
        ->and(AdminActivityLog::query()->where('event', 'sign_in_failed')->count())->toBe(2)
        ->and(AdminActivityLog::query()->where('actor_email', 'nobody@synapse.ph')->exists())->toBeTrue();
});

it('tells only somebody with the right password that the account is off', function () {
    $admin = Admin::factory()->inactive()->create();

    portalLogin($admin->email)
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'This admin account is deactivated. Ask a super admin to turn it back on.');
});

it('refuses a wrong code and accepts a right one on the same challenge', function () {
    $admin = Admin::factory()->create();
    $challenge = portalLogin($admin->email)->json('challenge');

    portalTwoFactor($challenge, '000000')->assertUnprocessable()->assertJsonValidationErrors('code');
    portalTwoFactor($challenge, portalCode($admin->two_factor_secret))->assertOk();
});

it('accepts each code only once', function () {
    $admin = Admin::factory()->create();
    $code = portalCode($admin->two_factor_secret);

    portalTwoFactor(portalLogin($admin->email)->json('challenge'), $code)->assertOk();

    portalTwoFactor(portalLogin($admin->email)->json('challenge'), $code)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('throws a challenge away after too many wrong codes', function () {
    $admin = Admin::factory()->create();
    $challenge = portalLogin($admin->email)->json('challenge');

    foreach (range(1, 4) as $attempt) {
        portalTwoFactor($challenge, '000000')->assertJsonValidationErrors('code');
    }

    portalTwoFactor($challenge, '000000')->assertJsonValidationErrors('challenge');
    portalTwoFactor($challenge, portalCode($admin->two_factor_secret))->assertJsonValidationErrors('challenge');

    expect(AdminActivityLog::query()->where('event', 'two_factor_failed')->count())->toBe(5);
});

it('refuses a made-up challenge', function () {
    portalTwoFactor(str_repeat('a', 64), '123456')->assertJsonValidationErrors('challenge');
});

it('slows down password guessing', function () {
    Admin::factory()->create(['email' => 'rhea@synapse.ph']);

    foreach (range(1, 5) as $attempt) {
        portalLogin('rhea@synapse.ph', 'Wrong-Password-1')->assertUnprocessable();
    }

    portalLogin('rhea@synapse.ph', 'Wrong-Password-1')->assertTooManyRequests();
});

it('ends a session left idle past the limit', function () {
    $admin = Admin::factory()->create();
    $headers = asPortalAdmin($admin);

    $this->getJson('/api/admin/me', $headers)->assertOk();

    $admin->tokens()->update(['last_used_at' => now()->subMinutes(31)]);

    $this->getJson('/api/admin/me', portalHeaders(substr($headers['Authorization'], 7)))->assertUnauthorized();
});

it('ends a session at its expiry however busy it is', function () {
    $admin = Admin::factory()->create();
    $headers = asPortalAdmin($admin);

    $admin->tokens()->update(['expires_at' => now()->subMinute(), 'last_used_at' => now()]);

    $this->getJson('/api/admin/me', $headers)->assertUnauthorized();
});

it('signs out by deleting the session', function () {
    $admin = Admin::factory()->create();
    $headers = asPortalAdmin($admin);

    $this->postJson('/api/admin/auth/logout', [], $headers)->assertOk();

    expect($admin->tokens()->count())->toBe(0)
        ->and(AdminActivityLog::query()->where('event', 'sign_out')->exists())->toBeTrue();
});

it('holds an admin with an issued password at the password change', function () {
    $admin = Admin::factory()->create(['must_change_password' => true]);
    $other = portalToken($admin);
    $headers = asPortalAdmin($admin);

    $this->getJson('/api/admin/overview', $headers)
        ->assertForbidden()
        ->assertJsonPath('reason', 'password_change_required');
    $this->getJson('/api/admin/me', $headers)->assertOk();

    $this->putJson('/api/admin/account/password', [
        'current_password' => 'Correct-Horse-9',
        'password' => 'Brand-New-Secret-7',
        'password_confirmation' => 'Brand-New-Secret-7',
    ], $headers)->assertOk()->assertJsonPath('admin.must_change_password', false);

    $this->getJson('/api/admin/overview', $headers)->assertOk();
    $this->getJson('/api/admin/me', portalHeaders($other))->assertUnauthorized();
});

it('checks a new password against the rules and the current one', function () {
    $headers = asPortalAdmin(Admin::factory()->create());

    $this->putJson('/api/admin/account/password', [
        'current_password' => 'Wrong-Horse-9',
        'password' => 'short',
        'password_confirmation' => 'short',
    ], $headers)->assertJsonValidationErrors(['current_password', 'password']);

    $this->putJson('/api/admin/account/password', [
        'current_password' => 'Correct-Horse-9',
        'password' => 'Correct-Horse-9',
        'password_confirmation' => 'Correct-Horse-9',
    ], $headers)->assertJsonValidationErrors(['password']);
});

it('signs out of every other session but this one', function () {
    $admin = Admin::factory()->create();
    portalToken($admin);
    portalToken($admin);
    $headers = asPortalAdmin($admin);

    $this->postJson('/api/admin/account/sessions/revoke-others', [], $headers)
        ->assertOk()
        ->assertJsonPath('ended', 2);

    expect($admin->tokens()->count())->toBe(1);
    $this->getJson('/api/admin/me', $headers)->assertOk();
});

<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;

/*
| ADR 0082 — Forgot password? on the app's sign-in screen: the app asks for
| Fortify's reset link, the web's reset page sets the password, and the phone
| sessions of the old password end.
*/

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

const APP_RESET_ANSWER = 'If an account uses that address, a link to reset its password is on its way.';

test('the app asks for the web’s reset link', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => " {$user->email} "])
        ->assertOk()
        ->assertExactJson(['message' => APP_RESET_ANSWER, 'expires_minutes' => 60]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_contains($url, '/reset-password/'.$notification->token)
            && str_contains($url, 'email='.urlencode($user->email));
    });
});

test('an address with no account gets the same answer, and nothing is sent', function () {
    Notification::fake();

    $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertExactJson(['message' => APP_RESET_ANSWER, 'expires_minutes' => 60]);

    Notification::assertNothingSent();
});

test('asking twice in a minute answers the same, and sends one link', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
        ->assertOk()
        ->assertJsonPath('message', APP_RESET_ANSWER);

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('the address must look like one', function (mixed $email) {
    $this->postJson('/api/auth/forgot-password', ['email' => $email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
})->with(['', 'not-an-email', null]);

test('a mail outage says so rather than claiming a link went out', function () {
    $broker = Mockery::mock();
    $broker->shouldReceive('sendResetLink')->andThrow(new RuntimeException('SMTP down'));
    Password::shouldReceive('broker')->andReturn($broker);

    $this->postJson('/api/auth/forgot-password', ['email' => 'someone@example.com'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'The email could not be sent just now. Try again in a few minutes.');
});

test('the endpoint is capped', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/auth/forgot-password', ['email' => "person{$i}@example.com"])->assertOk();
    }

    $this->postJson('/api/auth/forgot-password', ['email' => 'one.more@example.com'])->assertTooManyRequests();
});

test('a password reset from the link ends the app’s sessions', function () {
    Notification::fake();
    $user = User::factory()->create();
    $user->createToken('SYNAPSE Mobile');
    $user->createToken('Lost phone');

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return true;
    });

    $user->refresh();

    expect(Hash::check('a-new-long-password', $user->password))->toBeTrue()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);

    // The new password signs the app in again.
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'a-new-long-password'])->assertOk();
});

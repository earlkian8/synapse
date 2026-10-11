<?php

use App\Models\Employee;
use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0082 — a person edits their own sign-in account from the app (and the web's
| Settings, through the same OwnAccount): the name, the email address and the
| password. The employee record HR keeps is never touched.
*/

/** A signed-in app user with a real token, so revoking other sessions can be seen. */
function accountHolder(array $attributes = []): array
{
    // The factory may add a middle name and a suffix; pin them so names compare.
    $user = User::factory()->create(['password' => 'old-password', 'middle_name' => null, 'suffix' => null, ...$attributes]);
    $phone = $user->createToken('SYNAPSE Mobile')->plainTextToken;

    return [$user, $phone];
}

test('the app shows the account’s own details', function () {
    [$user] = accountHolder(['first_name' => 'Lara', 'last_name' => 'Quinto']);
    Sanctum::actingAs($user);

    $this->getJson('/api/account')
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Lara')
        ->assertJsonPath('data.full_name', 'Lara Quinto')
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonMissingPath('data.password');
});

test('the name is changed without a password, and the HR record is left alone', function () {
    $user = actingAsUserWith(['attendance.clock']);
    $user->update(['suffix' => null]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null]);
    Sanctum::actingAs($user);

    $this->patchJson('/api/account', ['first_name' => 'Mia', 'middle_name' => 'R', 'last_name' => 'Santos-Cruz', 'email' => $user->email])
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Mia R Santos-Cruz')
        ->assertJsonPath('email_changed', false)
        ->assertJsonPath('message', 'Saved.');

    expect($user->refresh()->first_name)->toBe('Mia')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($employee->refresh()->full_name)->toBe('Maria Santos');
});

test('a new email address needs the current password, and is confirmed again', function () {
    Notification::fake();
    [$user] = accountHolder();
    Sanctum::actingAs($user);
    $details = ['first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => 'new.address@example.com'];

    $this->patchJson('/api/account', $details)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password' => 'Enter your current password to change your email address.']);

    $this->patchJson('/api/account', [...$details, 'current_password' => 'not-it'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password' => 'That isn’t your current password.']);

    expect($user->refresh()->email)->not->toBe('new.address@example.com');

    $this->patchJson('/api/account', [...$details, 'current_password' => 'old-password'])
        ->assertOk()
        ->assertJsonPath('email_changed', true)
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('message', 'Saved. We sent a code to new.address@example.com to confirm it.');

    expect($user->refresh()->email)->toBe('new.address@example.com')
        ->and($user->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmailCodeNotification::class);
});

test('an address another account uses is refused', function () {
    $taken = User::factory()->create();
    [$user] = accountHolder();
    Sanctum::actingAs($user);

    $this->patchJson('/api/account', ['first_name' => 'A', 'last_name' => 'B', 'email' => $taken->email, 'current_password' => 'old-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('the name parts are required, as on the web', function () {
    [$user] = accountHolder();
    Sanctum::actingAs($user);

    $this->patchJson('/api/account', ['first_name' => '', 'last_name' => '', 'email' => $user->email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name', 'last_name']);
});

test('changing the password signs the app out on every other phone, not this one', function () {
    [$user, $phone] = accountHolder();
    $user->createToken('Old phone');
    $user->createToken('Tablet');

    $this->withToken($phone)->putJson('/api/account/password', [
        'current_password' => 'wrong',
        'password' => 'a-new-long-password',
        'password_confirmation' => 'a-new-long-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect($user->tokens()->count())->toBe(3);

    $this->withToken($phone)->putJson('/api/account/password', [
        'current_password' => 'old-password',
        'password' => 'a-new-long-password',
        'password_confirmation' => 'a-new-long-password',
    ])->assertOk()->assertJsonPath('message', 'Password changed. The app is signed out on your other devices.');

    $user->refresh();

    expect(Hash::check('a-new-long-password', $user->password))->toBeTrue()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and($user->tokens()->pluck('name')->all())->toBe(['SYNAPSE Mobile']);
});

test('the new password must be confirmed', function () {
    [, $phone] = accountHolder();

    $this->withToken($phone)->putJson('/api/account/password', [
        'current_password' => 'old-password',
        'password' => 'a-new-long-password',
        'password_confirmation' => 'something-else',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

test('an account in no company yet can still edit itself', function () {
    [$user, $phone] = accountHolder();
    // The factory joins a company; a registered account starts in none (ADR 0026).
    $user->memberships()->detach();

    expect($user->memberships()->exists())->toBeFalse();

    $this->withToken($phone)->patchJson('/api/account', ['first_name' => 'Solo', 'last_name' => 'Person', 'email' => $user->email])
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Solo Person');
});

test('the account endpoints need a session', function () {
    $this->getJson('/api/account')->assertUnauthorized();
    $this->patchJson('/api/account', [])->assertUnauthorized();
    $this->putJson('/api/account/password', [])->assertUnauthorized();
});

// ── The web's Settings, through the same OwnAccount ──────────────────────────

test('a password changed on the web signs the app out everywhere', function () {
    [$user] = accountHolder();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'old-password',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])
        ->assertSessionHasNoErrors();

    expect($user->tokens()->count())->toBe(0)
        ->and($user->refresh()->password_changed_at)->not->toBeNull();
});

test('a new email address on the web is sent its confirmation code', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['first_name' => 'Test', 'last_name' => 'User', 'email' => 'moved@example.com'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmailCodeNotification::class);
});

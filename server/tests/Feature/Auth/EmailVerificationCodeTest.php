<?php

use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use App\Support\EmailVerificationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

/**
 * Confirming an email address from a one-time code instead of a signed link.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

/** An unverified account holding a known, current code. */
function userAwaitingCode(): array
{
    $user = User::factory()->unverified()->create();
    $code = EmailVerificationCode::issueFor($user);

    return [$user->refresh(), $code];
}

// ── What the account is sent ─────────────────────────────────────────────────

test('registering emails a code rather than a link', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'organization_name' => 'Code Co',
        'first_name' => 'Cody',
        'last_name' => 'Codes',
        'email' => 'cody@codeco.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'cody@codeco.test')->firstOrFail();

    Notification::assertSentTo($user, VerifyEmailCodeNotification::class,
        function (VerifyEmailCodeNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return strlen($notification->code) === EmailVerificationCode::LENGTH
                // The whole point: nothing in the message is clickable.
                && $mail->actionUrl === null
                && str_contains($mail->subject, $notification->code);
        });
});

test('the code is stored hashed, never in the clear', function () {
    [$user, $code] = userAwaitingCode();

    expect($user->email_verification_code)->not->toBe($code)
        ->and($user->email_verification_code)->not->toContain($code)
        ->and($user->email_verification_code_expires_at->isFuture())->toBeTrue();
});

test('the stored code never reaches the client', function () {
    [$user] = userAwaitingCode();

    $this->actingAs($user)->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('auth.user.email_verification_code')
            ->etc());
});

// ── Spending it ──────────────────────────────────────────────────────────────

test('the code verifies the address', function () {
    Event::fake();
    [$user, $code] = userAwaitingCode();

    $this->actingAs($user)
        ->post(route('verification.code'), ['code' => $code])
        ->assertRedirect(route('workspaces', absolute: false));

    Event::assertDispatched(Verified::class);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a spent code is forgotten, so it cannot be used twice', function () {
    [$user, $code] = userAwaitingCode();

    $this->actingAs($user)->post(route('verification.code'), ['code' => $code]);

    $user->refresh();

    expect($user->email_verification_code)->toBeNull()
        ->and($user->email_verification_code_expires_at)->toBeNull()
        ->and(EmailVerificationCode::matches($user, $code))->toBeFalse();
});

test('a code pasted with the spaces the email prints still works', function () {
    [$user, $code] = userAwaitingCode();

    $spaced = trim(chunk_split($code, 2, ' '));

    $this->actingAs($user)
        ->post(route('verification.code'), ['code' => $spaced])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

// ── Refusing it ──────────────────────────────────────────────────────────────

test('a wrong code is refused', function () {
    Event::fake();
    [$user, $code] = userAwaitingCode();

    $wrong = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

    $this->actingAs($user)
        ->post(route('verification.code'), ['code' => $wrong])
        ->assertSessionHasErrors('code');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an expired code is refused', function () {
    [$user, $code] = userAwaitingCode();

    $this->travel(EmailVerificationCode::lifetime() + 1)->minutes();

    $this->actingAs($user)
        ->post(route('verification.code'), ['code' => $code])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('another account code does not verify mine', function () {
    [$mine] = userAwaitingCode();
    [, $theirs] = userAwaitingCode();

    $this->actingAs($mine)
        ->post(route('verification.code'), ['code' => $theirs])
        ->assertSessionHasErrors('code');

    expect($mine->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the code has to look like a code', function () {
    [$user] = userAwaitingCode();

    foreach (['', '12345', '1234567', 'abcdef'] as $bad) {
        $this->actingAs($user)
            ->post(route('verification.code'), ['code' => $bad])
            ->assertSessionHasErrors('code');
    }

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('guessing is rate limited', function () {
    [$user] = userAwaitingCode();

    // Six attempts a minute, then the door closes — a six-digit code is only
    // safe while guessing it is slow.
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($user)->post(route('verification.code'), ['code' => '000000']);
    }

    $this->actingAs($user)
        ->post(route('verification.code'), ['code' => '000000'])
        ->assertStatus(429);
});

// ── Resending ────────────────────────────────────────────────────────────────

test('asking for a new code invalidates the one before it', function () {
    Notification::fake();
    [$user, $first] = userAwaitingCode();

    $this->actingAs($user)->post(route('verification.send'))->assertRedirect();

    expect(EmailVerificationCode::matches($user->refresh(), $first))->toBeFalse();

    Notification::assertSentTo($user, VerifyEmailCodeNotification::class,
        fn (VerifyEmailCodeNotification $notification): bool => EmailVerificationCode::matches($user->refresh(), $notification->code));
});

// ── Already done ─────────────────────────────────────────────────────────────

test('an already-verified account is sent on rather than shown an error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.code'), ['code' => '000000'])
        ->assertRedirect(route('workspaces', absolute: false))
        ->assertSessionHasNoErrors();
});

test('the verification screen is closed to guests', function () {
    $this->post(route('verification.code'), ['code' => '000000'])
        ->assertRedirect(route('login'));
});

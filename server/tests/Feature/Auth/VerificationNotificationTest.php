<?php

use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('sends verification notification', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    // The address is confirmed by a code now, not a signed link (ADR 0033).
    Notification::assertSentTo($user, VerifyEmailCodeNotification::class);
});

test('does not send verification notification if email is verified', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('workspaces', absolute: false));

    Notification::assertNothingSent();
});

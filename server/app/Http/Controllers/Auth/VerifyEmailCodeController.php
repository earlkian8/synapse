<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Notifications\VerifyEmailCodeNotification;
use App\Support\EmailVerificationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Confirms an email address from the one-time code the account was sent, in place
 * of Fortify's signed-link route.
 *
 * The person is signed in but unverified when they land here (that is what
 * `verified` middleware bounces them for), so the account being confirmed is
 * always the caller's own — there is no id or hash in the request to trust, which
 * is the main thing a code buys over a link.
 *
 * Rate-limited at the route: a six-digit code is only safe while guessing it is
 * slow. See {@see EmailVerificationCode} and {@see VerifyEmailCodeNotification}.
 */
class VerifyEmailCodeController extends Controller
{
    public function store(VerifyEmailCodeRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Already done — a second tab, a double submit, or the back button.
        // Answer the same way a successful verification does rather than
        // complaining about a code that is legitimately gone.
        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(config('fortify.home'));
        }

        if (! EmailVerificationCode::matches($user, $request->validated('code'))) {
            throw ValidationException::withMessages([
                'code' => ['That code is wrong or has expired. Send yourself a new one below.'],
            ]);
        }

        $user->markEmailAsVerified();

        // Spent, so it cannot be used again — including by anyone who read the
        // mail after the fact.
        EmailVerificationCode::clear($user);

        event(new Verified($user));

        return redirect()->intended(config('fortify.home'));
    }
}

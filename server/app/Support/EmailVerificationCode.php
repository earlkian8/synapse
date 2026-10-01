<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * The one-time code an email address is confirmed with.
 *
 * Verification used to be a signed link — a button in an email. A code instead
 * means the person finishes signing up in the tab they are already in, which also
 * survives the mail client rewriting links and the address being read on a phone
 * while the browser is on a laptop.
 *
 * Deliberately **not** {@see JoinCode}, though both are short codes people retype.
 * A join code is Crockford base32 and is read aloud or off a printed slip, so it
 * trades keyspace for characters nobody can misread. This one is typed off the
 * screen next to it, immediately, and is numeric so the phone keyboard comes up
 * as a keypad and `autocomplete="one-time-code"` can fill it. Its safety comes
 * from being short-lived, single-use and rate-limited, not from its length.
 *
 * The stored code is hashed. The column sits on `users` beside `password`, and
 * possession of the code is what verifies the address — so it is treated as the
 * credential it is.
 */
class EmailVerificationCode
{
    /** Six digits, matching the OTP input the app already uses for 2FA. */
    public const LENGTH = 6;

    /**
     * Issue a fresh code for this user and return it in the clear — the one
     * moment it exists in readable form, for the notification to carry.
     *
     * Issuing replaces whatever came before, so asking for a new code invalidates
     * the old one rather than leaving two valid at once.
     */
    public static function issueFor(User $user): string
    {
        $code = self::generate();

        $user->forceFill([
            'email_verification_code' => Hash::make($code),
            'email_verification_code_expires_at' => now()->addMinutes(self::lifetime()),
        ])->save();

        return $code;
    }

    /**
     * Whether this is the user's current, unexpired code.
     *
     * Returns false rather than throwing for every way it can fail — no code
     * issued, expired, wrong — because the screen says the same thing to all
     * three: the code did not work, here is how to get another.
     */
    public static function matches(User $user, ?string $code): bool
    {
        $hash = $user->email_verification_code;
        $expiresAt = $user->email_verification_code_expires_at;

        if ($hash === null || $expiresAt === null || $expiresAt->isPast()) {
            return false;
        }

        return Hash::check(self::normalize($code), $hash);
    }

    /**
     * Forget the code. Called once the address is verified, so a code can never
     * be spent twice.
     */
    public static function clear(User $user): void
    {
        $user->forceFill([
            'email_verification_code' => null,
            'email_verification_code_expires_at' => null,
        ])->save();
    }

    /**
     * How long a code stays valid, in minutes. Much shorter than the hour the
     * signed link had: the code is typed on the screen that asked for it, so
     * there is no reason for one to outlive the sitting it was issued in.
     */
    public static function lifetime(): int
    {
        return (int) config('auth.verification.code_expire', 10);
    }

    /**
     * Digits only, trimmed — so a pasted code with a stray space still resolves.
     */
    public static function normalize(?string $code): string
    {
        return preg_replace('/\D/', '', (string) $code) ?? '';
    }

    /**
     * A random numeric code. Drawn digit by digit from `random_int` so it is
     * cryptographically random and can legitimately start with a zero, which
     * padding an integer would only ever do by accident.
     */
    private static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }
}

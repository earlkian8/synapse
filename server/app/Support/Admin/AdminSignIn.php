<?php

namespace App\Support\Admin;

use App\Models\Admin;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signing in to the admin portal (ADR 0085): a password, then always an
 * authenticator code. Nothing that grants access is handed out between the two.
 *
 * A right password earns a **challenge** — a random, single-use reference to the
 * pending sign-in, held server-side for a few minutes and good for a few wrong
 * codes. An admin who has never enrolled is given a fresh secret with it; that
 * secret lives only inside the (encrypted) challenge until a good code confirms
 * it, so an abandoned enrolment leaves nothing behind on the account.
 */
class AdminSignIn
{
    /**
     * Checked against when no admin has the address, so an unknown email costs
     * the same time as a wrong password and the two cannot be told apart.
     */
    private const DUMMY_HASH = '$2y$12$byEOTZHHqayaUUX.fpt2yeeLPPN5miBVqIAXGdei06tRpRtLwFxPW';

    private const CREDENTIALS_MESSAGE = 'These credentials do not match our records.';

    public function __construct(
        private readonly Repository $cache,
        private readonly AdminTwoFactor $twoFactor,
    ) {}

    /**
     * The admin these credentials belong to, or a validation error that says
     * nothing about which half was wrong.
     */
    public function verifyPassword(string $email, #[\SensitiveParameter] string $password): Admin
    {
        $email = mb_strtolower(trim($email));
        $admin = Admin::query()->where('email', $email)->first();

        if (! Hash::check($password, $admin?->password ?? self::DUMMY_HASH) || $admin === null) {
            AdminAudit::record(
                'sign_in_failed',
                $admin ? 'Sign-in failed: wrong password' : 'Sign-in failed: no admin has this email',
                actor: $admin,
                properties: ['reason' => $admin ? 'password' : 'unknown_email'],
                actorEmail: mb_substr($email, 0, 255),
            );

            throw ValidationException::withMessages(['email' => self::CREDENTIALS_MESSAGE]);
        }

        // Only somebody who knew the password learns the account is switched off.
        if (! $admin->is_active) {
            AdminAudit::record('sign_in_failed', 'Sign-in refused: the account is deactivated', actor: $admin, properties: ['reason' => 'inactive']);

            throw ValidationException::withMessages(['email' => 'This admin account is deactivated. Ask a super admin to turn it back on.']);
        }

        return $admin;
    }

    /**
     * Open the second step for an admin whose password was right.
     *
     * @return array{status: string, challenge: string, expires_in: int, setup?: array{secret: string, otpauth_url: string}}
     */
    public function begin(Admin $admin): array
    {
        $challenge = Str::random(64);
        $ttl = (int) config('admin.challenge_ttl') * 60;
        $setup = ! $admin->hasTwoFactor();
        $secret = $setup ? $this->twoFactor->generateSecret() : null;

        $this->cache->put($this->key($challenge), [
            'admin_id' => $admin->id,
            'attempts' => 0,
            'expires_at' => now()->addSeconds($ttl)->getTimestamp(),
            'pending_secret' => $secret !== null ? Crypt::encryptString($secret) : null,
        ], $ttl);

        $response = [
            'status' => $setup ? 'two_factor_setup' : 'two_factor',
            'challenge' => $challenge,
            'expires_in' => $ttl,
        ];

        if ($secret !== null) {
            $response['setup'] = [
                'secret' => $secret,
                'otpauth_url' => $this->twoFactor->otpauthUrl($admin, $secret),
            ];
        }

        return $response;
    }

    /**
     * Finish a sign-in with an authenticator code. Confirms the enrolment when
     * this was the admin's first code.
     */
    public function complete(string $challenge, #[\SensitiveParameter] string $code): Admin
    {
        $key = $this->key($challenge);
        $pending = $this->cache->get($key);
        $admin = is_array($pending) ? Admin::query()->find($pending['admin_id']) : null;
        $enrolling = is_array($pending) && $pending['pending_secret'] !== null;

        // Gone, timed out, switched off since — or enrolled meanwhile through
        // another sign-in, whose secret this one must not overwrite.
        if ($admin === null || ! $admin->is_active || ($enrolling && $admin->hasTwoFactor())) {
            $this->cache->forget($key);

            throw ValidationException::withMessages(['challenge' => 'Your sign-in timed out. Sign in again.']);
        }

        $secret = $enrolling ? Crypt::decryptString($pending['pending_secret']) : (string) $admin->two_factor_secret;

        if (! $this->twoFactor->verify($admin, $secret, $code)) {
            $this->recordWrongCode($key, $pending, $admin);
        }

        $this->cache->forget($key);

        if ($enrolling) {
            $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

            AdminAudit::record('two_factor_enrolled', 'Set up an authenticator app', actor: $admin);
        }

        return $admin;
    }

    /**
     * Count a wrong code against the challenge, and throw it away once it has
     * had its share.
     *
     * @param  array<string, mixed>  $pending
     */
    private function recordWrongCode(string $key, array $pending, Admin $admin): never
    {
        $attempts = $pending['attempts'] + 1;
        $remaining = $pending['expires_at'] - now()->getTimestamp();

        AdminAudit::record('two_factor_failed', 'Entered a wrong authenticator code', actor: $admin, properties: ['attempt' => $attempts]);

        if ($attempts >= (int) config('admin.challenge_attempts') || $remaining <= 0) {
            $this->cache->forget($key);

            throw ValidationException::withMessages(['challenge' => 'Too many wrong codes. Sign in again.']);
        }

        $this->cache->put($key, [...$pending, 'attempts' => $attempts], $remaining);

        throw ValidationException::withMessages(['code' => "That code didn't match. Check the time on your phone and enter the code your authenticator shows now."]);
    }

    private function key(string $challenge): string
    {
        return 'admin-sign-in:'.hash('sha256', $challenge);
    }
}

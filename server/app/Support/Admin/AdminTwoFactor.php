<?php

namespace App\Support\Admin;

use App\Models\Admin;
use Illuminate\Contracts\Cache\Repository;
use PragmaRX\Google2FA\Google2FA;

/**
 * The portal's authenticator codes (TOTP, RFC 6238), on the Google2FA engine
 * Fortify already brings.
 *
 * A code is accepted once. The slot it matched is remembered per admin, and the
 * next code must come from a later slot, so a code read over somebody's shoulder
 * (or replayed from a captured request) is dead the moment it has been used.
 * Unlike Fortify's own provider, the engine is always given that floor, so it
 * answers with the slot that matched rather than `true` — otherwise a code from
 * the window's next slot could be spent twice.
 */
class AdminTwoFactor
{
    public function __construct(
        private readonly Google2FA $engine,
        private readonly Repository $cache,
    ) {}

    /**
     * A new secret: 32 base32 characters, 160 bits.
     */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /**
     * The `otpauth://` address an authenticator app scans, naming the portal and
     * the admin's email.
     */
    public function otpauthUrl(Admin $admin, string $secret): string
    {
        return $this->engine->getQRCodeUrl((string) config('admin.two_factor_issuer'), $admin->email, $secret);
    }

    public function verify(Admin $admin, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $key = "admin-2fa-slot:{$admin->id}";
        $slot = $this->engine->verifyKeyNewer($secret, $code, (int) $this->cache->get($key, 0));

        if ($slot === false || $slot === true) {
            return false;
        }

        // Kept a little longer than any window a later code could fall in.
        $this->cache->put($key, $slot, now()->addMinutes(10));

        return true;
    }
}

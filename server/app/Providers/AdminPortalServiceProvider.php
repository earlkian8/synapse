<?php

namespace App\Providers;

use App\Models\Admin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * The platform admin portal's server side (ADR 0085): how hard its doors are to
 * knock on, and how long a session that nobody is using stays open.
 */
class AdminPortalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureIdleSessions();
    }

    private function configureRateLimiting(): void
    {
        // A password guess costs one attempt per address, and one address cannot
        // be used to dodge the per-IP cap by rotating emails.
        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(5)->by('admin-login:'.Str::transliterate(Str::lower((string) $request->input('email'))).'|'.$request->ip()),
            Limit::perMinute(20)->by('admin-login-ip:'.$request->ip()),
        ]);

        // Six digits are only safe while guessing them is slow. The challenge
        // itself also dies after a few wrong codes (config admin.challenge_attempts).
        RateLimiter::for('admin-two-factor', fn (Request $request) => [
            Limit::perMinute(10)->by('admin-2fa:'.$request->ip()),
        ]);

        RateLimiter::for('admin-api', fn (Request $request) => Limit::perMinute(120)
            ->by('admin-api:'.($request->user('admin')?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * A portal token stops working once it has gone unused for the idle limit,
     * even though it has hours left before `expires_at`. Sanctum asks this before
     * it stamps `last_used_at`, so the gap measured is the real one. Mobile tokens
     * pass through untouched.
     */
    private function configureIdleSessions(): void
    {
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid || $token->tokenable_type !== (new Admin)->getMorphClass()) {
                return $isValid;
            }

            $lastSeen = $token->last_used_at ?? $token->created_at;

            return $lastSeen !== null
                && $lastSeen->gt(now()->subMinutes((int) config('admin.idle_timeout')));
        });
    }
}

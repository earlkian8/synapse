<?php

namespace App\Support\Admin;

use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * What a portal password must be, and the one-time passwords the portal issues.
 *
 * Stricter than the ERP's (whose rules only tighten in production): an admin can
 * read every organisation, so twelve characters with both cases, a number and a
 * symbol everywhere, and checked against known breaches in production.
 */
class AdminPasswords
{
    public static function rule(): Password
    {
        $rule = Password::min(12)->letters()->mixedCase()->numbers()->symbols();

        return app()->isProduction() ? $rule->uncompromised() : $rule;
    }

    /**
     * A password for somebody else to use once: shown to the admin who issued it,
     * then replaced at the holder's first sign-in.
     */
    public static function temporary(): string
    {
        return Str::password(24);
    }
}

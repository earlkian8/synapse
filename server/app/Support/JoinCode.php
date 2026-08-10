<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Generates and normalises the short codes people retype to reach a workspace: an
 * organisation's join code and an employee invitation's claim code (ADR 0026).
 *
 * Both use Crockford's base32 alphabet, which omits I, L, O and U. That buys two
 * things a raw random string does not:
 *
 * - **Nothing to misread.** The pairs that get mistaken for one another over a
 *   phone call or off a printed slip (1/I/l, 0/O) only ever appear as the digit.
 * - **Self-healing input.** {@see normalize()} folds the omitted letters back onto
 *   the digit they resemble, so a code typed as `I` or `O` still resolves. Callers
 *   normalise once on the way in and compare exact strings after that.
 */
class JoinCode
{
    /**
     * Crockford base32 — the decimal digits plus the 22 unambiguous letters.
     */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * An organisation's join code: short enough to read aloud, long enough that
     * guessing one is hopeless (32^7 ≈ 3.4 × 10^10).
     */
    public const ORGANIZATION_LENGTH = 7;

    /**
     * An invitation's claim code. One character longer than a join code because
     * possession of it is, on its own, authorisation to occupy a roster line.
     */
    public const INVITATION_LENGTH = 8;

    /**
     * A random code of the given length, drawn from the alphabet above.
     */
    public static function generate(int $length): string
    {
        $alphabet = self::ALPHABET;
        $last = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * A code of the given length that no row of `$model` holds in `$column` yet.
     *
     * Uniqueness is checked across every tenant and past every soft delete: these
     * codes are redeemed *before* an organisation is bound, so a collision with
     * another tenant's code would be indistinguishable from a valid one.
     *
     * @param  class-string<Model>  $model
     */
    public static function uniqueFor(string $model, string $column, int $length): string
    {
        do {
            $code = self::generate($length);

            $taken = $model::query()
                ->withoutGlobalScopes()
                ->where($column, $code)
                ->exists();
        } while ($taken);

        return $code;
    }

    /**
     * Fold typed input onto the canonical alphabet: drop the spaces and dashes
     * people add for legibility, upper-case it, and map the letters the alphabet
     * omits onto the digits they look like.
     */
    public static function normalize(?string $input): string
    {
        $code = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $input) ?? '');

        return strtr($code, ['I' => '1', 'L' => '1', 'O' => '0']);
    }
}

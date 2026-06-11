<?php

namespace App\Support;

/**
 * Reversible, URL-safe obfuscation of integer ids — so a record's primary key is
 * not exposed as a guessable sequential number in URLs (e.g. `/recruitment/4`
 * becomes `/recruitment/9aQ2`).
 *
 * Uses Knuth's multiplicative hashing ("Optimus") over a 31-bit space — a bijection,
 * so every id maps to exactly one code and back — then base62-encodes the result.
 * Dependency-free and stable (the same id always yields the same code).
 */
class Hashid
{
    /** 2^31 — the modulus. Ids must be below this (comfortable for bigint PKs in practice). */
    private const MOD = 2147483648;

    /** A prime coprime to the modulus, its companion XOR mask. */
    private const PRIME = 1580030173;

    private const XOR = 1163945558;

    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Encode an integer id into a short URL-safe code.
     */
    public static function encode(int $id): string
    {
        $obfuscated = (($id * self::PRIME) % self::MOD) ^ self::XOR;

        return self::toBase62($obfuscated);
    }

    /**
     * Decode a code back to its id, or null if the code is malformed.
     */
    public static function decode(string $code): ?int
    {
        $obfuscated = self::fromBase62($code);

        if ($obfuscated === null) {
            return null;
        }

        return (($obfuscated ^ self::XOR) * self::inverse()) % self::MOD;
    }

    /**
     * The modular multiplicative inverse of PRIME mod 2^31 (memoised).
     */
    private static function inverse(): int
    {
        static $inverse = null;

        if ($inverse !== null) {
            return $inverse;
        }

        [$oldR, $r] = [self::PRIME, self::MOD];
        [$oldS, $s] = [1, 0];

        while ($r !== 0) {
            $q = intdiv($oldR, $r);
            [$oldR, $r] = [$r, $oldR - $q * $r];
            [$oldS, $s] = [$s, $oldS - $q * $s];
        }

        return $inverse = (($oldS % self::MOD) + self::MOD) % self::MOD;
    }

    private static function toBase62(int $number): string
    {
        if ($number === 0) {
            return '0';
        }

        $code = '';

        while ($number > 0) {
            $code = self::ALPHABET[$number % 62].$code;
            $number = intdiv($number, 62);
        }

        return $code;
    }

    private static function fromBase62(string $code): ?int
    {
        if ($code === '') {
            return null;
        }

        $number = 0;

        for ($i = 0, $len = strlen($code); $i < $len; $i++) {
            $position = strpos(self::ALPHABET, $code[$i]);

            if ($position === false) {
                return null;
            }

            $number = $number * 62 + $position;
        }

        return $number;
    }
}

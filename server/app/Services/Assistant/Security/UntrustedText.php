<?php

namespace App\Services\Assistant\Security;

use App\Services\Assistant\Assistant;
use App\Services\Assistant\Retrieval\ContextSection;

/**
 * Text somebody other than the asker wrote — a name, a leave reason, a task
 * title, a remark on a scorecard, an audit-log line — made safe to set in front
 * of the model.
 *
 * Every string that reaches the prompt from a record passes through here, at the
 * two boundaries it can cross: a retrieved context line
 * ({@see ContextSection}) and a tool result
 * ({@see Assistant::toFunctionResponse()}). Doing it at
 * the boundary rather than in each module is the point: a module added next year
 * is covered without anybody remembering to be.
 *
 * What it removes is structure, never meaning:
 *
 * - **Control and format characters** — newlines, tabs, and the invisible Unicode
 *   (`\p{Cf}`: zero-width joiners, bidi overrides, tag characters) that can hide a
 *   second instruction inside a first, or make text render differently from how
 *   the model reads it. A record can then never lay out a fake prompt turn.
 * - **Fence markers** — the delimiter that brackets retrieved data
 *   ({@see PromptFence}) cannot be forged from inside it, because the sequence
 *   that opens or closes a fence is broken up wherever it appears.
 * - **Length** — a field is capped, so one record cannot bury the brief.
 *
 * This is hygiene, not the guarantee. The guarantee is that the model only ever
 * *asks*: every tool re-checks the signed-in user's permission when it runs, and
 * anything consequential waits for the user to confirm it (ADR 0049).
 */
final class UntrustedText
{
    /** The default cap for one field of a record. */
    public const FIELD = 300;

    /** The cap for one composed line of a retrieved brief. */
    public const LINE = 900;

    /**
     * One field, flattened onto a single line and capped. Null (or nothing left
     * after cleaning) stays null, so callers can keep filtering blanks.
     */
    public static function clean(?string $value, int $max = self::FIELD): ?string
    {
        if ($value === null) {
            return null;
        }

        // Invalid UTF-8 would make every pattern below fail open (preg returns
        // null); drop the bytes rather than pass them through untouched.
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        $clean = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '';
        $clean = self::defuseFences($clean);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        if ($clean === '') {
            return null;
        }

        return mb_strimwidth($clean, 0, max(1, $max), '…');
    }

    /**
     * Like {@see clean()}, but keeps line breaks — for text the *asker* wrote and
     * a module will store (a job description, a note), where the lines are part
     * of what they meant. Invisible characters and fence markers still go.
     */
    public static function multiline(?string $value, int $max = 5000): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        // Everything invisible except the newline and the tab.
        $clean = preg_replace('/[^\P{Cc}\n\t]|[\p{Cf}\p{Zl}\p{Zp}]/u', '', $value) ?? '';
        $clean = self::defuseFences($clean);
        $clean = trim((string) preg_replace("/\n{3,}/", "\n\n", $clean));

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, max(1, $max));
    }

    /**
     * Break up anything that reads as one of our fence markers, so data can never
     * close the fence it is quoted in and continue as if it were the prompt.
     */
    private static function defuseFences(string $value): string
    {
        return (string) preg_replace('/<{2,}|>{2,}/u', '‹›', $value);
    }
}

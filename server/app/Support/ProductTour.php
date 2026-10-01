<?php

namespace App\Support;

use App\Models\User;

/**
 * The first-run tour of the app (ADR 0060): a short, role-aware walk around the
 * sidebar, the top bar and the assistant, offered once to everybody the first
 * time they reach the app shell.
 *
 * The steps themselves live in the browser — they point at what is on the
 * screen, and only the browser knows what is. What the server keeps is whether
 * the tour is still owed, so it is offered once rather than on every sign-in.
 *
 * The first answer stands. Replaying the tour from the Help menu is never
 * recorded, so somebody who finished it and later skips a replay still reads as
 * having finished it — and a double submit cannot rewrite the date.
 */
final class ProductTour
{
    /** Walked to the end. */
    public const COMPLETED = 'completed';

    /** Left before the end — the Skip button, the close button or Escape. */
    public const SKIPPED = 'skipped';

    /** @var list<string> */
    public const OUTCOMES = [self::COMPLETED, self::SKIPPED];

    /**
     * Whether the tour should still be offered to this person.
     */
    public static function owes(User $user): bool
    {
        return $user->tour_finished_at === null;
    }

    /**
     * Record how the tour ended. Idempotent: an answer already given is kept.
     *
     * Written with `forceFill` because tour state is not a profile field (it is
     * not fillable), and without touching `updated_at`, which `UserResource`
     * reports as the last edit to somebody's account — and this is not one.
     */
    public static function finish(User $user, string $outcome): void
    {
        if (! self::owes($user) || ! in_array($outcome, self::OUTCOMES, true)) {
            return;
        }

        User::withoutTimestamps(fn () => $user->forceFill([
            'tour_finished_at' => now(),
            'tour_outcome' => $outcome,
        ])->save());
    }
}

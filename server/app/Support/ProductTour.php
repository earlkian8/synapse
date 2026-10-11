<?php

namespace App\Support;

use App\Models\ProductTourRun;
use App\Models\User;

/**
 * The tour of the app (ADR 0060): a short, role-aware walk around the sidebar,
 * the top bar and the assistant, offered once to everybody the first time they
 * reach the app shell — and the mobile app's own walk round its tabs (ADR 0080).
 *
 * The steps themselves live in the browser (and the app) — they point at what
 * is on the screen, and only the client knows what is. What the server keeps is
 * whether a tour is still owed, so it is offered once rather than on every
 * sign-in, and which **version** was answered (ADR 0080): when the web tour
 * gains stops ({@see VERSION}), somebody who answered an earlier one is offered
 * a short "What's new" of the stops added since, once.
 *
 * The first answer stands. Replaying the tour from the Help menu never changes
 * what is owed, so somebody who finished it and later skips a replay still
 * reads as having finished it — and a double submit cannot rewrite the date.
 *
 * Every run — first, what's new, or a replay — is recorded
 * ({@see ProductTourRun}) for the per-stop numbers.
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
     * The web tour's version. Version 2 added the Search stop. Raise it when
     * stops are added, and mark them `since` it in the browser's step list.
     */
    public const VERSION = 2;

    /** The mobile app's tour's version. */
    public const MOBILE_VERSION = 1;

    /**
     * Whether a tour should still be offered to this person: the first one, or
     * the "What's new" of a later version.
     */
    public static function owes(User $user): bool
    {
        return self::mode($user) !== null;
    }

    /**
     * Which run is owed: `first`, `whats_new`, or null when none is.
     */
    public static function mode(User $user): ?string
    {
        if ($user->tour_finished_at === null) {
            return 'first';
        }

        return (int) ($user->tour_version ?? 1) < self::VERSION ? 'whats_new' : null;
    }

    /**
     * The tour state the browser reads (`auth.tour`).
     *
     * @return array{owed: bool, mode: ?string, version: int, seen_version: ?int}
     */
    public static function state(User $user): array
    {
        return [
            'owed' => self::owes($user),
            'mode' => self::mode($user),
            'version' => self::VERSION,
            // The version answered before — what "What's new" counts from.
            'seen_version' => $user->tour_finished_at === null ? null : (int) ($user->tour_version ?? 1),
        ];
    }

    /**
     * Record how the owed tour ended. Idempotent: the first answer stands, and a
     * "What's new" only moves the version on.
     *
     * Written with `forceFill` because tour state is not a profile field (it is
     * not fillable), and without touching `updated_at`, which `UserResource`
     * reports as the last edit to somebody's account — and this is not one.
     */
    public static function finish(User $user, string $outcome): void
    {
        $mode = self::mode($user);

        if ($mode === null || ! in_array($outcome, self::OUTCOMES, true)) {
            return;
        }

        User::withoutTimestamps(fn () => $user->forceFill($mode === 'first'
            ? ['tour_finished_at' => now(), 'tour_outcome' => $outcome, 'tour_version' => self::VERSION]
            : ['tour_version' => self::VERSION])->save());
    }

    /**
     * Whether the mobile app's tour should still be offered.
     */
    public static function owesMobile(User $user): bool
    {
        return (int) ($user->mobile_tour_version ?? 0) < self::MOBILE_VERSION;
    }

    /**
     * Record that the mobile tour was answered. Idempotent.
     */
    public static function finishMobile(User $user, string $outcome): void
    {
        if (! self::owesMobile($user) || ! in_array($outcome, self::OUTCOMES, true)) {
            return;
        }

        User::withoutTimestamps(fn () => $user->forceFill([
            'mobile_tour_version' => self::MOBILE_VERSION,
            'mobile_tour_finished_at' => now(),
        ])->save());
    }

    /**
     * Open a run of the tour.
     *
     * @param  list<string>  $steps
     */
    public static function startRun(User $user, string $platform, string $mode, array $steps): ProductTourRun
    {
        return ProductTourRun::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'version' => $platform === 'mobile' ? self::MOBILE_VERSION : self::VERSION,
            'mode' => $mode,
            'steps' => array_values($steps),
            'furthest_index' => 0,
            'last_step' => $steps[0] ?? null,
            'started_at' => now(),
        ]);
    }

    /**
     * Move a run on to a step. Going back never lowers how far it got.
     */
    public static function reach(ProductTourRun $run, int $index): void
    {
        if ($run->outcome !== null) {
            return;
        }

        $steps = $run->steps ?? [];
        $index = max(0, min($index, count($steps) - 1));

        $run->forceFill([
            'furthest_index' => max($run->furthest_index, $index),
            'last_step' => $steps[$index] ?? $run->last_step,
        ])->save();
    }

    /**
     * Close a run with how it ended. The first answer stands.
     */
    public static function endRun(ProductTourRun $run, string $outcome): void
    {
        if ($run->outcome !== null || ! in_array($outcome, self::OUTCOMES, true)) {
            return;
        }

        $steps = $run->steps ?? [];

        $run->forceFill([
            'outcome' => $outcome,
            'finished_at' => now(),
            // Finishing means the last step was reached.
            'furthest_index' => $outcome === self::COMPLETED ? max(0, count($steps) - 1) : $run->furthest_index,
            'last_step' => $outcome === self::COMPLETED ? ($steps[count($steps) - 1] ?? $run->last_step) : $run->last_step,
        ])->save();
    }
}

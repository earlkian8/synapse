<?php

namespace App\Services\Assistant\Contracts;

use App\Models\User;

/**
 * A module whose held calls can say what they would reach.
 *
 * A confirmation card shows *what* would change — the tool and its cleaned
 * arguments (ADR 0049). For a setting that decides how other people's days are
 * judged, that is not enough to consent to: "grace minutes: 10" says nothing
 * about the 140 people it applies to. This is the line that does, computed from
 * the records when the call is held, before anybody presses Confirm.
 *
 * Read-only, and never decisive: returning null leaves the card as it was, and
 * whatever it says, the call itself is re-checked and re-validated when it runs.
 */
interface ExplainsConsequences
{
    /**
     * One plain sentence on what the held call would reach — "Judges 42 people
     * today; days already recorded keep their rules." — or null.
     *
     * @param  array<string, mixed>  $args  The call's arguments, already cleaned.
     */
    public function consequence(User $user, string $tool, array $args): ?string;
}

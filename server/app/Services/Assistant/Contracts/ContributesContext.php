<?php

namespace App\Services\Assistant\Contracts;

use App\Models\User;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;

/**
 * A module that can describe a subject, not just act on one.
 *
 * {@see AssistantModule} is the *action* surface: named tools the model chooses
 * between. This is the *retrieval* surface: given a person the turn is about,
 * the module returns what it knows about them, so the answer can be composed
 * from the record instead of from a tool call the model had to think to make.
 *
 * The contract is deliberately small and deliberately synchronous — it runs on
 * every turn that resolves a subject, before the model is called at all. So:
 *
 * - **Check the permission yourself.** The orchestrator only guarantees the
 *   module is available to this user; whether they may see *this* slice is the
 *   module's own question. Self-service is the usual exception:
 *   {@see RetrievedSubject::$isSelf} means the subject is the asker.
 * - **Return null when there is nothing to say.** An empty section is noise in
 *   the prompt and a lie in the timeline.
 * - **Keep it bounded.** A handful of lines. This is a briefing, not an export.
 */
interface ContributesContext
{
    /**
     * What this module knows about the subject, or null when it knows nothing
     * (or the asker may not see it).
     */
    public function contextFor(User $user, RetrievedSubject $subject): ?ContextSection;
}

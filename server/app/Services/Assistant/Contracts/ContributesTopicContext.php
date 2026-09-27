<?php

namespace App\Services\Assistant\Contracts;

use App\Models\User;
use App\Services\Assistant\Retrieval\ContextSection;

/**
 * A module that can describe the workspace, not just a person in it.
 *
 * {@see ContributesContext} answers "what do you know about Maria?". This answers
 * the other half of what people ask an HR assistant — "how are we doing today?",
 * "what needs my attention?", "how far through the review cycle are we?" — where
 * there is no person to resolve, only a topic.
 *
 * The retriever decides a turn is about a module's topic by matching the
 * {@see topicTriggers()} against the words the user typed — a deterministic
 * match on the asker's own message, never something the model decided — and
 * only when the turn did not resolve to a person (a person's brief is the better
 * answer to a question about them).
 *
 * The same three duties as a subject contribution: check the permission
 * yourself, return null when there is nothing to say, and keep it to a handful
 * of lines.
 */
interface ContributesTopicContext
{
    /**
     * Lowercase words or short phrases that make a turn about this module's topic.
     * A single word matches as a whole word; a phrase matches as written.
     *
     * @return list<string>
     */
    public function topicTriggers(): array;

    /**
     * What this module can say about the workspace to this user, or null when it
     * has nothing (or they may not see it).
     */
    public function topicContext(User $user): ?ContextSection;
}

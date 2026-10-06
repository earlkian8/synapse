<?php

namespace App\Support\Search;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One kind of thing global search can find (ADR 0069): employees, job postings,
 * screens, help articles…
 *
 * A source is asked only when it {@see permits()} the person searching, and it
 * answers with what that person could open anyway — so search never shows
 * anybody more than the screens do.
 */
interface SearchSource
{
    /** The group's key in the response (`employees`, `help`, …). */
    public function key(): string;

    /** The group's heading in the palette. */
    public function label(): string;

    public function permits(User $user): bool;

    /**
     * The best matches, best first, at most {@see GlobalSearch::LIMIT}.
     *
     * @param  list<string>  $words  what was typed, word by word, lowercased
     * @param  string  $phrase  the whole of it, lowercased and squished
     * @return Collection<int, SearchResult>
     */
    public function find(User $user, array $words, string $phrase): Collection;
}

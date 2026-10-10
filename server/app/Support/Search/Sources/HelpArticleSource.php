<?php

namespace App\Support\Search\Sources;

use App\Models\User;
use App\Support\Help\HelpCenter;
use App\Support\Search\GlobalSearch;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchSource;
use Illuminate\Support\Collection;

/**
 * Help Center articles (ADR 0062), searched as the Help Center searches them —
 * which already leaves out every article about a screen the person cannot open.
 */
class HelpArticleSource implements SearchSource
{
    public function key(): string
    {
        return 'help';
    }

    public function label(): string
    {
        return 'Help';
    }

    /** Every person may search the manual — it is read for them. */
    public function permits(User $user): bool
    {
        return true;
    }

    public function find(User $user, array $words, string $phrase): Collection
    {
        return HelpCenter::search($user, $phrase, GlobalSearch::LIMIT)['results']
            ->map(fn (array $article): SearchResult => new SearchResult(
                id: 'help:'.$article['slug'],
                title: $article['title'],
                subtitle: $article['category_title'],
                hint: null,
                href: $article['href'],
            ))
            ->values();
    }
}

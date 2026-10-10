<?php

namespace App\Support\Search\Sources;

use App\Models\User;
use App\Support\Search\GlobalSearch;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchSource;
use App\Support\SystemGuide;
use Illuminate\Support\Collection;

/**
 * The screens of SYNAPSE, from the assistant's {@see SystemGuide}: found by
 * their names and by what people go there for ("holidays" finds Work Schedule
 * & Holidays). The guide only lists screens the person may open.
 */
class ScreenSource implements SearchSource
{
    public function key(): string
    {
        return 'screens';
    }

    public function label(): string
    {
        return 'Screens';
    }

    /** Every person may search screens — the guide leaves out the ones they cannot open. */
    public function permits(User $user): bool
    {
        return true;
    }

    public function find(User $user, array $words, string $phrase): Collection
    {
        return SystemGuide::search($user, $phrase, GlobalSearch::LIMIT)
            ->map(fn (array $screen, string $key): SearchResult => new SearchResult(
                id: 'screen:'.$key,
                title: $screen['title'],
                subtitle: $screen['menu'],
                hint: null,
                href: $screen['path'],
            ))
            ->values();
    }
}

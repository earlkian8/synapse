<?php

namespace App\Support\Search;

use App\Models\User;
use App\Support\Search\Sources\ApplicantSource;
use App\Support\Search\Sources\AppraisalSource;
use App\Support\Search\Sources\DepartmentSource;
use App\Support\Search\Sources\EmployeeSource;
use App\Support\Search\Sources\EventSource;
use App\Support\Search\Sources\HelpArticleSource;
use App\Support\Search\Sources\JobPostingSource;
use App\Support\Search\Sources\LeaveRequestSource;
use App\Support\Search\Sources\OffboardingCaseSource;
use App\Support\Search\Sources\OnboardingCaseSource;
use App\Support\Search\Sources\RoleSource;
use App\Support\Search\Sources\ScreenSource;
use App\Support\Search\Sources\TrainingProgramSource;
use App\Support\Search\Sources\UserSource;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The ⌘K palette's search (ADR 0069): one query, asked of every kind of thing
 * the person searching may open, answered in groups.
 *
 * It is always read **for a user**. A source the person has no permission for
 * is not queried at all, and every record source asks for exactly the ability
 * its own screen's route checks — so search never shows anybody a record the
 * screens would not. With no company bound it answers nothing: the tenant
 * scope is a no-op then, and would otherwise see every company's rows.
 *
 * Reading changes nothing, so nothing here is activity-logged.
 */
final class GlobalSearch
{
    /** How many results a kind shows. */
    public const LIMIT = 5;

    /** The most words of a search that are matched. */
    private const MAX_WORDS = 6;

    /**
     * Every source, in the order its group appears. Fixed, so the palette does
     * not reshuffle while somebody types.
     *
     * @var list<class-string<SearchSource>>
     */
    private const SOURCES = [
        ScreenSource::class,
        EmployeeSource::class,
        ApplicantSource::class,
        JobPostingSource::class,
        OnboardingCaseSource::class,
        OffboardingCaseSource::class,
        LeaveRequestSource::class,
        AppraisalSource::class,
        TrainingProgramSource::class,
        EventSource::class,
        DepartmentSource::class,
        UserSource::class,
        RoleSource::class,
        HelpArticleSource::class,
    ];

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * @return list<array{key: string, label: string, items: list<array<string, string|null>>}>
     */
    public function search(User $user, string $query): array
    {
        $words = self::words($query);

        if (! $this->tenancy->check() || $words === []) {
            return [];
        }

        $phrase = implode(' ', $words);
        $groups = [];

        foreach ($this->sources() as $source) {
            if (! $source->permits($user)) {
                continue;
            }

            $items = $source->find($user, $words, $phrase);

            if ($items->isNotEmpty()) {
                $groups[] = [
                    'key' => $source->key(),
                    'label' => $source->label(),
                    'items' => $items->map(fn (SearchResult $result): array => $result->toArray())->all(),
                ];
            }
        }

        return $groups;
    }

    /**
     * @return list<SearchSource>
     */
    public function sources(): array
    {
        return array_map(fn (string $source): SearchSource => app($source), self::SOURCES);
    }

    /**
     * What was typed, word by word: lowercased, each once, at most six. A
     * "word" with no letter or digit in it (`%%`, `--`) is dropped — the
     * modules' search scopes would read it as a wildcard and match everybody.
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $words = preg_split('/\s+/u', Str::of($query)->lower()->squish()->toString(), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => preg_match('/[\p{L}\p{N}]/u', $word) === 1,
        ))), 0, self::MAX_WORDS);
    }

    /**
     * Apply the model's `search` scope once per word, ANDed: "maria santos"
     * finds the person whose first name is Maria *and* whose last is Santos,
     * though no one column holds both. The assistant's `matchByTokens` does the
     * same for the modules it drives.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $words
     * @return TBuilder
     */
    public static function matchEveryWord(Builder $query, array $words): Builder
    {
        foreach ($words as $word) {
            $query->search($word);
        }

        return $query;
    }

    /** A value for `like ? escape '!'`, with its own wildcards taken literally. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}

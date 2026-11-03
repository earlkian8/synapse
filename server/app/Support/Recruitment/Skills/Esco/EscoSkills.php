<?php

namespace App\Support\Recruitment\Skills\Esco;

use App\Models\EscoSkill;
use App\Models\EscoSkillTerm;
use App\Support\Recruitment\Skills\SkillCatalogue;
use App\Support\Recruitment\Skills\SkillMatcher;
use App\Support\Recruitment\Skills\TaxonomySkill;
use App\Support\Recruitment\Skills\Words;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Everything the ATS asks ESCO (ADR 0100), and the store of what it learned.
 *
 *  - {@see learn()}: the skills of a posting the catalogue doesn't know, after
 *    the posting is saved and daily, so the fit score finds the answers in the
 *    database ({@see EscoTaxonomy}).
 *  - {@see reading()}, {@see suggest()} and {@see forRole()}: what the posting
 *    form shows while HR types.
 *
 * The catalogue always comes first: a skill it knows is never sent to ESCO.
 * Only skill names and job titles are ever sent, never anything about a
 * candidate. When ESCO can't be reached, what was already known stands and
 * the term is asked again later.
 */
class EscoSkills
{
    /** How many of ESCO's results are weighed for one skill. */
    private const CANDIDATES = 10;

    /** A pause between two lookups in one run, to be a polite client of a free service. */
    private const PAUSE_MS = 150;

    /** What a term must have to be worth asking about. */
    private const MIN_TERM = 3;

    /** How many suggestions ESCO is asked for, before those that don't start with what was typed are dropped. */
    private const SUGGEST_ASK = 15;

    /** The longest skill a posting takes (StoreJobPostingRequest). */
    private const MAX_SKILL = 60;

    /** How many of a role's essential and optional skills are offered. */
    private const ROLE_SKILLS = 24;

    /** Words that rank a role without changing which role it is ("Senior accountant"). */
    private const SENIORITY = ['senior', 'junior', 'sr', 'jr', 'entry level', 'mid level', 'trainee', 'i', 'ii', 'iii', 'iv'];

    public function __construct(
        private readonly EscoClient $client,
        private readonly SkillCatalogue $catalogue,
        private readonly bool $enabled = true,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The skills among these that the catalogue doesn't know — each "or"
     * alternative on its own — once each, as HR wrote them.
     *
     * @param  iterable<mixed>  $labels
     * @return list<string>
     */
    public function unknownTerms(iterable $labels): array
    {
        $terms = [];

        foreach ($labels as $label) {
            foreach (SkillMatcher::alternatives(trim((string) $label)) as $alternative) {
                $key = EscoSkillTerm::keyFor($alternative);

                if (mb_strlen($key) < self::MIN_TERM || isset($terms[$key]) || $this->catalogue->resolve($alternative) !== null) {
                    continue;
                }

                $terms[$key] = $alternative;
            }
        }

        return array_values($terms);
    }

    /**
     * Ask ESCO about each of these skills the catalogue doesn't know and that
     * has no fresh answer (every one, `$again`). Returns how many were asked.
     *
     * @param  iterable<mixed>  $labels
     */
    public function learn(iterable $labels, bool $again = false, ?int $limit = null): int
    {
        if (! $this->enabled) {
            return 0;
        }

        $terms = $this->unknownTerms($labels);

        if ($terms === []) {
            return 0;
        }

        $known = EscoSkillTerm::query()
            ->with('skill')
            ->whereIn('term', array_map(EscoSkillTerm::keyFor(...), $terms))
            ->get()
            ->keyBy('term');

        $asked = 0;

        foreach ($terms as $text) {
            $row = $known->get(EscoSkillTerm::keyFor($text));

            if ((! $again && $row?->isFresh()) || ($limit !== null && $asked >= $limit)) {
                continue;
            }

            if ($asked > 0) {
                Sleep::usleep(self::PAUSE_MS * 1000);
            }

            $this->ask($text, $row);
            $asked++;
        }

        return $asked;
    }

    /**
     * ESCO's skill for one term: the stored answer while it is fresh, or
     * ESCO's own. Null when ESCO has none, or can't be reached and nothing was
     * known before.
     */
    public function lookUp(string $text, bool $again = false): ?EscoConcept
    {
        $key = EscoSkillTerm::keyFor($text);

        if (! $this->enabled || mb_strlen($key) < self::MIN_TERM) {
            return null;
        }

        $row = EscoSkillTerm::query()->with('skill')->where('term', $key)->first();

        if (! $again && $row?->isFresh()) {
            return $row->status === EscoSkillTerm::MATCHED ? $row->skill?->toConcept() : null;
        }

        return $this->ask($text, $row);
    }

    /**
     * How the ATS will read one skill a posting asks for, for the posting
     * form: the catalogue's, ESCO's (looked up now if need be), or neither —
     * then it is matched by its words.
     *
     * @return array{term: string, source: 'catalogue'|'esco'|null, esco: array{label: string, uri: string, url: string, description: string|null, same: string|null, also: list<string>, proven_by: list<string>, related: list<string>}|null}
     */
    public function reading(string $text): array
    {
        $text = trim($text);

        if ($this->catalogue->resolve($text) !== null) {
            return ['term' => $text, 'source' => 'catalogue', 'esco' => null];
        }

        $concept = $this->lookUp($text);

        if ($concept === null) {
            return ['term' => $text, 'source' => null, 'esco' => null];
        }

        $skill = TaxonomySkill::fromEsco($concept, $this->catalogue);
        $names = fn (array $skills): array => array_values(array_unique(array_map(fn ($s): string => $s->name, $skills)));

        return ['term' => $text, 'source' => 'esco', 'esco' => [
            'label' => $concept->label,
            'uri' => $concept->uri,
            'url' => $concept->portalUrl(),
            'description' => $concept->description,
            'same' => $skill->same?->name,
            'also' => array_slice($concept->altLabels, 0, 6),
            'proven_by' => array_slice($names($skill->provers), 0, 8),
            'related' => array_slice($names($skill->parents), 0, 8),
        ]];
    }

    /**
     * ESCO skills whose names start with what HR is typing, for the posting
     * form's suggestions. `also` is the other label that matched ("bookkeeping"
     * for accounting); `catalogue` the catalogue skill a suggestion is, when it
     * is one. Kept for a day; empty when ESCO can't be reached.
     *
     * @return list<array{label: string, also: string|null, catalogue: string|null}>
     */
    public function suggest(string $text, int $limit = 6): array
    {
        $folded = Words::fold($text);

        if (! $this->enabled || mb_strlen($folded) < self::MIN_TERM) {
            return [];
        }

        try {
            $suggestions = Cache::remember(
                $this->cacheKey('suggest', $folded),
                now()->addDay(),
                fn (): array => $this->askSuggestions($folded),
            );
        } catch (EscoUnavailable) {
            return [];
        }

        return array_slice($suggestions, 0, max(1, $limit));
    }

    /**
     * The occupation ESCO has for a job title, with the skills it lists as
     * essential and optional, for the posting form to offer as must-haves and
     * nice-to-haves. `exact` says whether the title names the occupation, or it
     * is only ESCO's closest. Kept for 30 days; null when ESCO has nothing or
     * can't be reached.
     *
     * @return array{occupation: array{label: string, uri: string, url: string, exact: bool}, essential: list<array{name: string, catalogue: bool}>, optional: list<array{name: string, catalogue: bool}>}|null
     */
    public function forRole(string $title): ?array
    {
        $folded = Words::fold($title);

        if (! $this->enabled || mb_strlen($folded) < self::MIN_TERM) {
            return null;
        }

        try {
            // Cached as an array even when there is no occupation, so a title
            // ESCO doesn't know isn't asked about on every click.
            $role = Cache::remember(
                $this->cacheKey('role', $folded),
                now()->addDays(30),
                fn (): array => ['role' => $this->askRole($title)],
            );
        } catch (EscoUnavailable) {
            return null;
        }

        return $role['role'];
    }

    /**
     * Ask ESCO about one term and keep the answer. When ESCO can't be reached a
     * skill already matched stays matched; anything else is marked failed and
     * asked again later.
     */
    private function ask(string $text, ?EscoSkillTerm $row): ?EscoConcept
    {
        $key = EscoSkillTerm::keyFor($text);

        try {
            $concept = $this->find($text);
        } catch (EscoUnavailable) {
            if ($row?->status === EscoSkillTerm::MATCHED) {
                return $row->skill?->toConcept();
            }

            $this->record($key, null, EscoSkillTerm::FAILED, ($row->attempts ?? 0) + 1);

            return null;
        }

        $skill = $concept === null ? null : EscoSkill::remember($concept);

        $this->record($key, $skill?->id, $skill === null ? EscoSkillTerm::NONE : EscoSkillTerm::MATCHED, 0);

        return $concept;
    }

    /**
     * The ESCO skill a term names, by what HR typed and then by its core
     * ("Strong forklift operation skills" → "forklift operation"). ESCO's
     * search is loose, so each result must name the term ({@see EscoLabels}).
     *
     * @throws EscoUnavailable
     */
    private function find(string $text): ?EscoConcept
    {
        // What HR typed, then its core if that is any different.
        $core = Words::core(Words::fold($text));
        $queries = array_values(array_filter([trim($text), $core !== Words::fold($text) ? $core : ''], fn (string $query): bool => $query !== ''));

        foreach ($queries as $query) {
            $concepts = [];

            foreach ($this->client->search($query, 'skill', self::CANDIDATES) as $result) {
                if ($concept = EscoConcept::fromApi($result, $this->client->language())) {
                    $concepts[] = $concept;
                }
            }

            if ($best = EscoLabels::best($text, $concepts)) {
                return $best;
            }
        }

        return null;
    }

    private function record(string $key, ?int $skillId, string $status, int $attempts): void
    {
        EscoSkillTerm::query()->upsert(
            [['term' => $key, 'esco_skill_id' => $skillId, 'status' => $status, 'attempts' => $attempts, 'looked_up_at' => now()]],
            ['term'],
            ['esco_skill_id', 'status', 'attempts', 'looked_up_at'],
        );
    }

    /**
     * @return list<array{label: string, also: string|null, catalogue: string|null}>
     *
     * @throws EscoUnavailable
     */
    private function askSuggestions(string $folded): array
    {
        $typed = explode(' ', $folded);
        $suggestions = [];

        foreach ($this->client->search($folded, 'skill', self::SUGGEST_ASK, full: false) as $result) {
            $label = EscoConcept::clean($result['title'] ?? null);
            $hit = self::asLabel($result['searchHit'] ?? null);

            if ($label === null || mb_strlen($label) > self::MAX_SKILL || ! str_starts_with((string) ($result['uri'] ?? ''), EscoConcept::URI_PREFIX)) {
                continue;
            }

            $startsWithTyped = fn (?string $text): bool => $text !== null && self::startsWords(Words::fold($text), $typed);

            if (! $startsWithTyped($label) && ! $startsWithTyped($hit)) {
                continue;
            }

            $suggestions[mb_strtolower($label)] ??= [
                'label' => Str::ucfirst($label),
                'also' => $hit !== null && Words::fold($hit) !== Words::fold($label) ? $hit : null,
                'catalogue' => $this->catalogue->named($label)?->name,
            ];
        }

        return array_values($suggestions);
    }

    /**
     * @return array{occupation: array{label: string, uri: string, url: string, exact: bool}, essential: list<array{name: string, catalogue: bool}>, optional: list<array{name: string, catalogue: bool}>}|null
     *
     * @throws EscoUnavailable
     */
    private function askRole(string $title): ?array
    {
        $core = $this->roleCore($title);
        $best = null;
        $bestScore = 0.0;
        $closest = null;

        foreach ($this->client->search($core, 'occupation', 5) as $result) {
            $uri = is_string($result['uri'] ?? null) ? $result['uri'] : '';
            $label = EscoConcept::clean(is_array($result['preferredLabel'] ?? null) ? ($result['preferredLabel'][$this->client->language()] ?? null) : ($result['title'] ?? null));

            if (! str_starts_with($uri, 'http://data.europa.eu/esco/occupation/') || $label === null) {
                continue;
            }

            $alt = $result['alternativeLabel'][$this->client->language()] ?? [];
            $score = EscoLabels::conceptScore($core, $label, is_array($alt) ? array_values(array_filter($alt, 'is_string')) : []);

            if ($score > $bestScore) {
                [$best, $bestScore] = [[$result, $uri, $label], $score];
            }

            // ESCO's first answer that matched a label, not a description.
            if ($closest === null && self::asLabel($result['searchHit'] ?? null) !== null) {
                $closest = [$result, $uri, $label];
            }
        }

        $chosen = $best ?? $closest;

        if ($chosen === null) {
            return null;
        }

        [$result, $uri, $label] = $chosen;
        $links = is_array($result['_links'] ?? null) ? $result['_links'] : [];

        return [
            'occupation' => [
                'label' => Str::ucfirst($label),
                'uri' => $uri,
                'url' => 'https://esco.ec.europa.eu/en/classification/occupation?uri='.rawurlencode($uri),
                'exact' => $best !== null,
            ],
            'essential' => $this->roleSkills($links['hasEssentialSkill'] ?? null),
            'optional' => $this->roleSkills($links['hasOptionalSkill'] ?? null),
        ];
    }

    /**
     * A role's skills as a posting would list them: the catalogue's name where
     * the catalogue knows the skill, ESCO's label otherwise.
     *
     * @return list<array{name: string, catalogue: bool}>
     */
    private function roleSkills(mixed $links): array
    {
        $skills = [];

        foreach (is_array($links) ? $links : [] as $link) {
            $label = is_array($link) ? EscoConcept::clean($link['title'] ?? null) : null;

            if ($label === null || mb_strlen($label) > self::MAX_SKILL) {
                continue;
            }

            $known = $this->catalogue->named($label);
            $name = $known?->name ?? Str::ucfirst($label);
            $skills[mb_strtolower($name)] ??= ['name' => $name, 'catalogue' => $known !== null];

            if (count($skills) >= self::ROLE_SKILLS) {
                break;
            }
        }

        return array_values($skills);
    }

    /**
     * A title without the words that only rank it: "Senior accountant II" →
     * "accountant".
     */
    private function roleCore(string $title): string
    {
        $words = implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), self::SENIORITY));
        $core = trim((string) preg_replace("/^(?:(?:{$words})\\s+)+|(?:\\s+(?:{$words}))+$/", '', Words::fold($title)));

        return $core === '' ? Words::fold($title) : $core;
    }

    /**
     * ESCO's search hit when it is a label ("bookkeeping"), not a line of
     * the description it matched in.
     */
    private static function asLabel(mixed $hit): ?string
    {
        $hit = EscoConcept::clean($hit);

        return $hit !== null && mb_strlen($hit) <= 80 && ! str_contains($hit, '. ') && ! str_ends_with($hit, '.') ? $hit : null;
    }

    /**
     * Whether every typed word starts a word of the text, in any order:
     * "forkl" starts "operate forklift".
     *
     * @param  list<string>  $typed
     */
    private static function startsWords(string $folded, array $typed): bool
    {
        $words = explode(' ', $folded);

        foreach ($typed as $prefix) {
            $found = false;

            foreach ($words as $word) {
                if (str_starts_with($word, $prefix)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function cacheKey(string $kind, string $folded): string
    {
        return "esco:{$kind}:{$this->client->language()}:".md5($folded);
    }
}

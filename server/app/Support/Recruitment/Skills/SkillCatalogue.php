<?php

namespace App\Support\Recruitment\Skills;

use InvalidArgumentException;

/**
 * Every skill the ATS knows and how they relate (ADR 0097): which spellings
 * are the same skill, which skills prove others (TypeScript proves
 * JavaScript), and which are close enough to earn partial credit (React and
 * Vue). The data is `catalogue.php`; this class indexes it once per process.
 */
final class SkillCatalogue
{
    private static ?self $shared = null;

    /** How many provers and relatives {@see describe()} names per skill. */
    private const DESCRIBED = 8;

    /** @var array<string, Skill> */
    private array $skills = [];

    /** @var array<string, string> joined spelling → skill key */
    private array $lookup = [];

    /** @var array<string, string> stemmed spelling → skill key */
    private array $stemLookup = [];

    /** @var array<string, list<string>> skill key → keys of the skills that prove it, nearest first */
    private array $provers = [];

    /** @var array<string, list<array{key: string, credit: float, family: string}>> */
    private array $relatives = [];

    /** @var array<string, true> every word that is some skill's spelling */
    private array $knownWords = [];

    /**
     * @param  array{skills: array<string, array<string, mixed>>, families?: array<string, array{credit: float, skills: list<string>}>}  $data
     */
    public function __construct(array $data)
    {
        foreach ($data['skills'] as $key => $entry) {
            $this->skills[$key] = Skill::fromEntry($key, $entry);
        }

        foreach ($this->skills as $skill) {
            foreach ([...$skill->implies, ...$skill->parts] as $implied) {
                if (! isset($this->skills[$implied])) {
                    throw new InvalidArgumentException("Skill “{$skill->key}” refers to an unknown skill “{$implied}”.");
                }
            }

            $spellings = [
                ...array_column($skill->spellings, 'joined'),
                ...array_map(fn (string $word): string => implode('', Words::tokens($word)), [...$skill->cased, ...$skill->listed]),
            ];

            foreach ($spellings as $joined) {
                $joined = (string) $joined;
                $this->knownWords[$joined] = true;

                $this->lookup[$joined] ??= $skill->key;
            }

            foreach ($skill->spellings as $spelling) {
                $this->stemLookup[implode(' ', array_map(Words::stem(...), $spelling['tokens']))] ??= $skill->key;
            }
        }

        $this->indexProvers();
        $this->indexFamilies($data['families'] ?? []);
    }

    /**
     * The catalogue in `catalogue.php`, built once per process.
     */
    public static function shared(): self
    {
        return self::$shared ??= new self(require __DIR__.'/catalogue.php');
    }

    /**
     * @return array<string, Skill>
     */
    public function all(): array
    {
        return $this->skills;
    }

    public function get(string $key): ?Skill
    {
        return $this->skills[$key] ?? null;
    }

    /**
     * The skill a posting means by what HR typed, or null when the catalogue
     * doesn't know it ("BIR compliance" → BIR compliance; "Advanced Excel
     * skills" → Excel; "ReactJS" → React).
     */
    public function resolve(string $text): ?Skill
    {
        $folded = Words::fold($text);

        if ($folded === '') {
            return null;
        }

        foreach (array_unique([$folded, Words::core($folded)]) as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $key = $this->lookup[str_replace(' ', '', $candidate)]
                ?? $this->stemLookup[implode(' ', array_map(Words::stem(...), explode(' ', $candidate)))]
                ?? null;

            if ($key !== null) {
                return $this->skills[$key];
            }
        }

        return null;
    }

    /**
     * Skills that prove this one, nearest first: for JavaScript, TypeScript
     * and React before Next.js.
     *
     * @return list<Skill>
     */
    public function proversOf(Skill $skill): array
    {
        return array_map(fn (string $key): Skill => $this->skills[$key], $this->provers[$skill->key] ?? []);
    }

    /**
     * The skills this one is built on directly that are worth partial credit
     * when it is missing: JavaScript for TypeScript, PHP for Laravel. Broad
     * areas (Programming) never count.
     *
     * @return list<Skill>
     */
    public function parentsOf(Skill $skill): array
    {
        return array_values(array_filter(
            array_map(fn (string $key): Skill => $this->skills[$key], $skill->implies),
            fn (Skill $parent): bool => ! $parent->umbrella && ! in_array($parent->key, $skill->parts, true),
        ));
    }

    /**
     * Skills in a family with this one, with the family's credit, best first.
     *
     * @return list<array{skill: Skill, credit: float, family: string}>
     */
    public function relativesOf(Skill $skill): array
    {
        return array_map(
            fn (array $relative): array => ['skill' => $this->skills[$relative['key']], 'credit' => $relative['credit'], 'family' => $relative['family']],
            $this->relatives[$skill->key] ?? [],
        );
    }

    /**
     * Whether a folded word is some skill's spelling — a word that is
     * another skill is never read as a slip of this one.
     */
    public function isKnownWord(string $word): bool
    {
        return isset($this->knownWords[$word]);
    }

    /**
     * What the posting form needs to say how each skill is read: its name, what
     * else counts as it, what proves it and what is related.
     *
     * The skills that prove it and the related ones are the nearest few;
     * `proven_by_count` says how many prove it in all.
     *
     * @return array{skills: list<array{name: string, aliases: list<string>, proven_by: list<string>, proven_by_count: int, related: list<string>}>, qualifiers: list<string>, generic: list<string>}
     */
    public function describe(): array
    {
        $skills = [];

        foreach ($this->skills as $skill) {
            $provers = array_map(fn (Skill $prover): string => $prover->name, $this->proversOf($skill));
            $related = array_values(array_unique([
                ...array_map(fn (Skill $parent): string => $parent->name, $this->parentsOf($skill)),
                ...array_map(fn (array $relative): string => $relative['skill']->name, $this->relativesOf($skill)),
            ]));

            $skills[] = [
                'name' => $skill->name,
                'aliases' => $skill->aliases(),
                'proven_by' => array_slice($provers, 0, self::DESCRIBED),
                'proven_by_count' => count($provers),
                'related' => array_slice($related, 0, self::DESCRIBED),
            ];
        }

        usort($skills, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['skills' => $skills, 'qualifiers' => Words::QUALIFIERS, 'generic' => Words::GENERIC];
    }

    /**
     * Invert `implies` (parts included) into "proven by", transitively and
     * breadth-first, so the nearest prover is named first. A loop is a
     * mistake in the data and is refused.
     */
    private function indexProvers(): void
    {
        $provenBy = [];

        foreach ($this->skills as $skill) {
            foreach ($skill->implies as $implied) {
                $provenBy[$implied][] = $skill->key;
            }
        }

        foreach ($this->skills as $skill) {
            $seen = [$skill->key => true];
            $queue = $provenBy[$skill->key] ?? [];
            $order = [];

            while ($queue !== []) {
                $next = array_shift($queue);

                if ($next === $skill->key) {
                    throw new InvalidArgumentException("Skill “{$skill->key}” proves itself through a loop.");
                }

                if (isset($seen[$next])) {
                    continue;
                }

                $seen[$next] = true;
                $order[] = $next;
                array_push($queue, ...($provenBy[$next] ?? []));
            }

            $this->provers[$skill->key] = $order;
        }
    }

    /**
     * @param  array<string, array{credit: float, skills: list<string>}>  $families
     */
    private function indexFamilies(array $families): void
    {
        foreach ($families as $family => $definition) {
            foreach ($definition['skills'] as $member) {
                if (! isset($this->skills[$member])) {
                    throw new InvalidArgumentException("Family “{$family}” names an unknown skill “{$member}”.");
                }

                foreach ($definition['skills'] as $other) {
                    if ($other === $member) {
                        continue;
                    }

                    $current = $this->relatives[$member][$other] ?? null;

                    if ($current === null || $current['credit'] < $definition['credit']) {
                        $this->relatives[$member][$other] = ['key' => $other, 'credit' => (float) $definition['credit'], 'family' => $family];
                    }
                }
            }
        }

        foreach ($this->relatives as $member => $relatives) {
            usort($relatives, fn (array $a, array $b): int => $b['credit'] <=> $a['credit']);
            $this->relatives[$member] = array_values($relatives);
        }
    }
}

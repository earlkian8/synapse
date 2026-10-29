<?php

namespace App\Support\Recruitment\Skills;

/**
 * Decides, for each skill a posting asks for, how a candidate shows it
 * (ADR 0097). In order of preference:
 *
 *  - `found`    the skill by any of its names: "JS" for JavaScript.
 *  - `implied`  a skill that proves it: TypeScript for JavaScript, Next.js
 *               for React. Full credit — nobody writes TypeScript without
 *               knowing JavaScript.
 *  - `parts`    everything it is made of: React and Laravel for full-stack.
 *  - `spelling` the name one letter off: "Javscript".
 *  - `related`  a head start, for partial credit: JavaScript when TypeScript
 *               is asked for, Vue for React, some of a stack's parts.
 *  - `missing`  none of these.
 *
 * A skill the catalogue doesn't know is matched by its words, in any order
 * and form ("project coordination" in "coordinated three projects"). HR may
 * accept alternatives with "or" ("React or Vue").
 */
final class SkillMatcher
{
    /** Credit for the skill another is built on (JavaScript for TypeScript). */
    public const PARENT_CREDIT = 0.4;

    /**
     * How many extra words may sit between the words of a known skill's name
     * when it is written in other words ("managed several projects"). A skill
     * the catalogue doesn't know gets more room.
     */
    private const NAME_SLACK = 1;

    private const PHRASE_SLACK = 3;

    /** Credit for a stack when only some of its parts are shown, scaled by the share shown. */
    public const PARTS_CREDIT = 0.8;

    /** How a status ranks when two alternatives earn the same credit. */
    private const PREFERENCE = ['found' => 5, 'implied' => 4, 'parts' => 3, 'spelling' => 2, 'related' => 1, 'missing' => 0];

    /** @var array<string, array{skill: Skill|null, stems: list<string>}> */
    private array $resolved = [];

    public function __construct(private readonly SkillCatalogue $catalogue) {}

    public static function make(): self
    {
        return new self(SkillCatalogue::shared());
    }

    /**
     * Assess every must-have and nice-to-have skill against the candidate.
     *
     * @param  list<string>  $must
     * @param  list<string>  $nice
     * @return list<array{skill: string, tier: string, status: string, via: string|null, source: string|null, credit: float, recognised: bool}>
     */
    public function match(array $must, array $nice, CandidateEvidence $evidence): array
    {
        $results = [];

        foreach (['must' => $must, 'nice' => $nice] as $tier => $skills) {
            foreach ($skills as $label) {
                $results[] = ['skill' => $label, 'tier' => $tier, ...$this->assess($label, $evidence)];
            }
        }

        return $results;
    }

    /**
     * The best showing of one skill HR wrote, across its "or" alternatives.
     *
     * @return array{status: string, via: string|null, source: string|null, credit: float, recognised: bool}
     */
    public function assess(string $label, CandidateEvidence $evidence): array
    {
        $alternatives = array_values(array_filter(array_map('trim', preg_split('/\s+or\s+/i', $label) ?: [$label])));
        $alternatives = count($alternatives) > 1 ? array_slice($alternatives, 0, 4) : [$label];

        $best = null;
        $recognised = false;

        foreach ($alternatives as $alternative) {
            $result = $this->assessOne($alternative, $evidence);
            $recognised = $recognised || $result['recognised'];

            if ($best === null
                || $result['credit'] > $best['credit']
                || ($result['credit'] === $best['credit'] && self::PREFERENCE[$result['status']] > self::PREFERENCE[$best['status']])) {
                $best = $result;
            }
        }

        return [...$best, 'recognised' => $recognised];
    }

    /**
     * @return array{status: string, via: string|null, source: string|null, credit: float, recognised: bool}
     */
    private function assessOne(string $text, CandidateEvidence $evidence): array
    {
        $resolved = $this->resolved[$text] ??= [
            'skill' => $this->catalogue->resolve($text),
            'stems' => Words::contentStems($text),
        ];

        $skill = $resolved['skill'];

        if ($skill === null) {
            $hit = $evidence->phrase($resolved['stems'], self::PHRASE_SLACK);

            return $hit === null
                ? $this->result('missing', null, null, 0.0, false)
                : $this->result('found', $this->differs($hit['surface'], $text), $hit['source'], 1.0, false);
        }

        if ($hit = $evidence->find($skill)) {
            return $this->result('found', $this->differs($hit['surface'], $skill->name), $hit['source'], 1.0);
        }

        foreach ($this->catalogue->proversOf($skill) as $prover) {
            if ($hit = $evidence->find($prover)) {
                return $this->result('implied', $prover->name, $hit['source'], 1.0);
            }
        }

        [$shown, $source] = $this->partsShown($skill, $evidence);

        if ($skill->parts !== [] && count($shown) === count($skill->parts)) {
            return $this->result('parts', implode(' + ', $shown), $source, 1.0);
        }

        if ($hit = $this->inOtherWords($skill, $evidence)) {
            return $this->result('found', $hit['surface'], $hit['source'], 1.0);
        }

        if ($hit = $evidence->misspelling($skill, $this->catalogue)) {
            return $this->result('spelling', $hit['surface'], $hit['source'], 1.0);
        }

        return $this->related($skill, $shown, $source, $evidence)
            ?? $this->result('missing', null, null, 0.0);
    }

    /**
     * The best partial credit: some of a stack's parts, the skill this one is
     * built on, or a skill of the same family (each shown directly or proven).
     *
     * @param  list<string>  $shown  the parts already found
     * @return array{status: string, via: string|null, source: string|null, credit: float, recognised: bool}|null
     */
    private function related(Skill $skill, array $shown, ?string $source, CandidateEvidence $evidence): ?array
    {
        $best = null;

        if ($shown !== []) {
            $credit = round(self::PARTS_CREDIT * count($shown) / count($skill->parts), 2);
            $best = $this->result('related', implode(' + ', $shown).' ('.count($shown).' of '.count($skill->parts).')', $source, $credit);
        }

        $candidates = [
            ...array_map(fn (Skill $parent): array => ['skill' => $parent, 'credit' => self::PARENT_CREDIT], $this->catalogue->parentsOf($skill)),
            ...$this->catalogue->relativesOf($skill),
        ];

        foreach ($candidates as $candidate) {
            if ($best !== null && $candidate['credit'] <= $best['credit']) {
                continue;
            }

            $hit = $this->shows($candidate['skill'], $evidence);

            if ($hit !== null) {
                // "React", or "React, through Next.js" when a skill proving it was what they wrote.
                $via = $hit['name'] === $candidate['skill']->name ? $hit['name'] : "{$candidate['skill']->name}, through {$hit['name']}";
                $best = $this->result('related', $via, $hit['source'], $candidate['credit']);
            }
        }

        return $best;
    }

    /**
     * A known skill of two or more words written in other words: "Project
     * management" in "managed several projects", "Inventory management" in
     * "managed the inventory". One word is never loosened this way — "driving"
     * would be in "drives growth".
     *
     * @return array{surface: string, source: string}|null
     */
    private function inOtherWords(Skill $skill, CandidateEvidence $evidence): ?array
    {
        $stems = Words::contentStems($skill->name);

        return count($stems) >= 2 ? $evidence->phrase($stems, self::NAME_SLACK) : null;
    }

    /**
     * The names of the parts the candidate shows, and where the first was found.
     *
     * @return array{0: list<string>, 1: string|null}
     */
    private function partsShown(Skill $skill, CandidateEvidence $evidence): array
    {
        $shown = [];
        $source = null;

        foreach ($skill->parts as $key) {
            $hit = $this->shows($this->catalogue->get($key), $evidence);

            if ($hit !== null) {
                $shown[] = $hit['name'];
                $source ??= $hit['source'];
            }
        }

        return [$shown, $source];
    }

    /**
     * Whether the candidate shows a skill directly or through one that proves
     * it, naming the skill they actually wrote.
     *
     * @return array{name: string, source: string}|null
     */
    private function shows(Skill $skill, CandidateEvidence $evidence): ?array
    {
        if ($hit = $evidence->find($skill)) {
            return ['name' => $skill->name, 'source' => $hit['source']];
        }

        foreach ($this->catalogue->proversOf($skill) as $prover) {
            if ($hit = $evidence->find($prover)) {
                return ['name' => $prover->name, 'source' => $hit['source']];
            }
        }

        return null;
    }

    /**
     * The spelling a candidate used, when it isn't simply the skill's name.
     */
    private function differs(string $surface, string $name): ?string
    {
        return strcasecmp(implode(' ', Words::tokens($surface)), implode(' ', Words::tokens($name))) === 0 ? null : $surface;
    }

    /**
     * @return array{status: string, via: string|null, source: string|null, credit: float, recognised: bool}
     */
    private function result(string $status, ?string $via, ?string $source, float $credit, bool $recognised = true): array
    {
        return ['status' => $status, 'via' => $via, 'source' => $source, 'credit' => $credit, 'recognised' => $recognised];
    }
}

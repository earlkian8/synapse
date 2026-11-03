<?php

namespace App\Support\Recruitment\Skills;

use App\Support\Recruitment\Skills\Esco\EscoConcept;
use App\Support\Recruitment\Skills\Esco\EscoLabels;

/**
 * A skill the catalogue doesn't know, as a taxonomy describes it (ADR 0100),
 * laid onto the catalogue wherever the two agree.
 *
 *  - `same`: the catalogue skill it is, when its preferred label is a
 *    catalogue name ("Python (computer programming)" is Python). The skill is
 *    then matched exactly as the catalogue matches it.
 *  - Otherwise `skill` holds its own names: the preferred label and its other
 *    labels, each also without a parenthesis or a light verb ("perform
 *    venepuncture procedures" is written "venepuncture" too). A one-word name
 *    counts only if it can't be an everyday word: the preferred label's own
 *    word, or a long term of art among the other labels.
 *  - `provers`: what shows it in full — the catalogue skills its other labels
 *    name (with the skills that prove those), and the skills just narrower
 *    than it. Narrower is a kind of it: "conduct forklift inspections" is
 *    forklift work.
 *  - `parents`: the skills just broader than it, a head start for partial
 *    credit, as a catalogue skill's parent is. A broad area of the catalogue
 *    (Programming) never counts.
 *
 * A name of two or more meaningful words is also found in other words
 * ("operated forklifts" for "operate forklift"), through {@see phrasesOf()}.
 */
final class TaxonomySkill
{
    /** A one-word name shorter than this could be anything. */
    private const MIN_WORD = 4;

    /**
     * An other label of one word counts only this long: a term of art
     * ("phlebotomy", "bookkeeping"), not an everyday word ("site", "quality").
     * The preferred label's own word counts from {@see MIN_WORD}.
     */
    private const MIN_OTHER_WORD = 10;

    /**
     * @param  list<Skill>  $provers
     * @param  list<Skill>  $parents
     * @param  array<string, list<list<string>>>  $phrases  skill key → the stems of its names of two or more words
     */
    public function __construct(
        public readonly string $source,
        public readonly string $uri,
        public readonly string $label,
        public readonly ?Skill $same,
        public readonly Skill $skill,
        public readonly array $provers = [],
        public readonly array $parents = [],
        private readonly array $phrases = [],
    ) {}

    public static function fromEsco(EscoConcept $concept, SkillCatalogue $catalogue): self
    {
        foreach (EscoLabels::forms($concept->label) as $form) {
            if ($same = $catalogue->named($form)) {
                return new self('esco', $concept->uri, $concept->label, $same, new Skill(self::key($concept->uri), $concept->label, [], [], [], [], [], false));
            }
        }

        $phrases = [];
        $provers = [];
        $parents = [];

        $own = self::named(self::key($concept->uri), $concept->label, $concept->labels(), $catalogue, $phrases, $provers);

        foreach ($concept->narrower as $narrower) {
            $skill = self::named(self::linkKey($narrower['uri']), $narrower['label'], [$narrower['label']], $catalogue, $phrases, $provers);

            if ($skill !== null) {
                $provers[] = $skill;
            }
        }

        foreach ($concept->broader as $broader) {
            $skill = self::named(self::linkKey($broader['uri']), $broader['label'], [$broader['label']], $catalogue, $phrases, $parents);

            if ($skill !== null) {
                $parents[] = $skill;
            }
        }

        $provers = self::unique($provers);
        $proverKeys = array_map(fn (Skill $skill): string => $skill->key, $provers);

        return new self(
            source: 'esco',
            uri: $concept->uri,
            label: $concept->label,
            same: null,
            skill: $own ?? new Skill(self::key($concept->uri), $concept->label, [], [], [], [], [], false),
            provers: $provers,
            parents: array_values(array_filter(self::unique($parents), fn (Skill $s): bool => ! in_array($s->key, $proverKeys, true))),
            phrases: $phrases,
        );
    }

    /**
     * The stems of a skill's names of two or more meaningful words.
     *
     * @return list<list<string>>
     */
    public function phrasesOf(Skill $skill): array
    {
        return $this->phrases[$skill->key] ?? [];
    }

    /**
     * A skill made of these labels. A label the catalogue knows is not a name
     * of it: the catalogue skill is added to `$bridged` instead, so the
     * catalogue's own care — "Excel" only with its capital, "R" only in a
     * list — and the skills that prove it still apply. Null when nothing is
     * left to find it by.
     *
     * @param  list<string>  $labels  the preferred label first
     * @param  array<string, list<list<string>>>  $phrases
     * @param  list<Skill>  $bridged
     */
    private static function named(string $key, string $name, array $labels, SkillCatalogue $catalogue, array &$phrases, array &$bridged): ?Skill
    {
        $spellings = [];

        foreach ($labels as $index => $label) {
            $minWord = $index === 0 ? self::MIN_WORD : self::MIN_OTHER_WORD;

            foreach (EscoLabels::forms($label) as $form) {
                $known = $catalogue->named($form);

                if ($known !== null) {
                    // A broad area is never proof of something specific.
                    if (! $known->umbrella) {
                        $bridged[] = $known;
                    }

                    continue;
                }

                // A name with one meaningful word ("use a forklift") is that
                // word, held to what a one-word name must be.
                $content = array_values(array_filter(explode(' ', $form), fn (string $word): bool => ! in_array($word, Words::FILLER, true)));

                if (count($content) <= 1) {
                    $word = $content[0] ?? null;
                    $known = $word === null ? null : $catalogue->named($word);

                    if ($known !== null && ! $known->umbrella) {
                        $bridged[] = $known;
                    } elseif ($known === null && $word !== null && self::isDistinctive($word, $minWord) && ($spelling = Skill::spelling($word))) {
                        $spellings[$spelling['joined']] ??= $spelling;
                    }

                    continue;
                }

                $spelling = Skill::spelling($form);

                if ($spelling !== null) {
                    $spellings[$spelling['joined']] ??= $spelling;
                }

                $stems = Words::contentStems($form);

                if (count($stems) >= 2) {
                    $phrases[$key][implode(' ', $stems)] = $stems;
                }
            }
        }

        if (isset($phrases[$key])) {
            $phrases[$key] = array_values($phrases[$key]);
        }

        if ($spellings === [] && ! isset($phrases[$key])) {
            return null;
        }

        return new Skill($key, $name, array_values($spellings), [], [], [], [], false);
    }

    /**
     * Whether one word can stand for a skill on its own: long enough, and not a
     * word that only qualifies one ("advanced", "skills", "procedures").
     */
    private static function isDistinctive(string $word, int $minLength): bool
    {
        return strlen($word) >= $minLength
            && ! ctype_digit($word)
            && ! in_array($word, Words::FILLER, true)
            && ! in_array($word, Words::GENERIC, true)
            && ! in_array($word, EscoLabels::LIGHT_VERBS, true)
            && ! in_array($word, EscoLabels::TRAILING, true);
    }

    /**
     * @param  list<Skill>  $skills
     * @return list<Skill>
     */
    private static function unique(array $skills): array
    {
        $unique = [];

        foreach ($skills as $skill) {
            $unique[$skill->key] ??= $skill;
        }

        return array_values($unique);
    }

    private static function key(string $uri): string
    {
        return 'esco:'.basename($uri);
    }

    /**
     * A broader or narrower skill is known only by its label, so it is a
     * different skill to the matcher than the same concept with all its labels.
     */
    private static function linkKey(string $uri): string
    {
        return 'esco-link:'.basename($uri);
    }
}

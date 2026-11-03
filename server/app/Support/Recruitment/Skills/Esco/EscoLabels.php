<?php

namespace App\Support\Recruitment\Skills\Esco;

use App\Support\Recruitment\Skills\Words;

/**
 * How ESCO's labels are compared with what HR typed (ADR 0100).
 *
 * ESCO's search is a full-text search: asked for "MS Excel" it answers
 * "pursue excellence in the creation of food products". So a concept is
 * accepted only when one of its labels *names* what was typed:
 *
 *  - the same words ("bookkeeping" is an other label of "accounting");
 *  - the same words once a parenthesis or ESCO's light verb is set aside
 *    ("Python" for "Python (computer programming)", "phlebotomy" for "perform
 *    phlebotomy");
 *  - the same meaningful words in any order and form ("forklift operation"
 *    for "operate forklift").
 *
 * ESCO writes British English, so both sides are compared in American
 * spelling ("call center agent" is ESCO's "call centre agent"), and every
 * label is also matched in American spelling.
 *
 * Anything looser is not a match, and the skill is matched by its words as
 * before.
 */
final class EscoLabels
{
    /** Verbs ESCO starts a skill with that say little about which skill it is. */
    public const LIGHT_VERBS = ['perform', 'conduct', 'carry out', 'use', 'utilise', 'utilize', 'apply', 'undertake', 'execute', 'employ', 'practise', 'practice'];

    /** Nouns ESCO ends a skill with that don't change which skill it is. */
    public const TRAILING = ['procedure', 'procedures', 'technique', 'techniques', 'method', 'methods', 'principles', 'practices', 'activities', 'tasks'];

    /** How strongly each kind of agreement counts, for the preferred label; an other label counts a little less. */
    private const EXACT = 1.0;

    private const REDUCED = 0.8;

    private const SAME_WORDS = 0.6;

    private const OTHER_LABEL = 0.1;

    /**
     * The folded forms of a label worth comparing: as written, without a
     * parenthesis, and without a light verb or trailing noun — each also in
     * American spelling. The label as written comes first.
     *
     * @return list<string>
     */
    public static function forms(string $label): array
    {
        $folded = Words::fold($label);
        $bare = Words::fold((string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $label));
        $forms = array_filter([$folded, $bare, self::reduce($folded), self::reduce($bare)]);

        return array_values(array_unique([...$forms, ...array_map(BritishSpelling::american(...), $forms)]));
    }

    /**
     * A folded label without its leading light verb and trailing generic noun:
     * "perform venepuncture procedures" → "venepuncture". Never empty: a label
     * that is nothing else ("use") is kept whole.
     */
    public static function reduce(string $folded): string
    {
        $verbs = implode('|', array_map(fn (string $verb): string => preg_quote($verb, '/'), self::LIGHT_VERBS));
        $trailing = implode('|', array_map(fn (string $noun): string => preg_quote($noun, '/'), self::TRAILING));

        $reduced = (string) preg_replace("/^(?:{$verbs})\\s+/", '', $folded);
        $reduced = trim((string) preg_replace("/\\s+(?:{$trailing})$/", '', $reduced));

        return $reduced === '' ? $folded : $reduced;
    }

    /**
     * How well one label names what was typed: 0 when it doesn't.
     */
    public static function score(string $typed, string $label, bool $preferred = true): float
    {
        $american = fn (array $forms): array => array_values(array_unique(array_map(BritishSpelling::american(...), $forms)));
        $typedForms = $american(self::typedForms($typed));
        $labelForms = $american(self::forms($label));

        if ($typedForms === [] || $labelForms === []) {
            return 0.0;
        }

        $score = match (true) {
            in_array($labelForms[0], $typedForms, true) => self::EXACT,
            array_intersect($typedForms, $labelForms) !== [] => self::REDUCED,
            self::sameWords($typedForms, $labelForms) => self::SAME_WORDS,
            default => 0.0,
        };

        return $score > 0 && ! $preferred ? $score - self::OTHER_LABEL : $score;
    }

    /**
     * The concept among these (in ESCO's order) that is named what was typed,
     * the best-named first; null when none is.
     *
     * @param  list<EscoConcept>  $concepts
     */
    public static function best(string $typed, array $concepts): ?EscoConcept
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($concepts as $concept) {
            $score = self::conceptScore($typed, $concept->label, $concept->altLabels);

            if ($score > $bestScore) {
                [$best, $bestScore] = [$concept, $score];
            }
        }

        return $best;
    }

    /**
     * The best score of a preferred label and its other labels.
     *
     * @param  list<string>  $altLabels
     */
    public static function conceptScore(string $typed, string $label, array $altLabels): float
    {
        $score = self::score($typed, $label);

        foreach ($altLabels as $alt) {
            if ($score >= self::EXACT) {
                break;
            }

            $score = max($score, self::score($typed, $alt, preferred: false));
        }

        return $score;
    }

    /**
     * What HR typed, folded, without the words around it that don't change
     * which skill it is ({@see Words::core()}), and reduced.
     *
     * @return list<string>
     */
    private static function typedForms(string $typed): array
    {
        $folded = Words::fold($typed);
        $core = Words::core($folded);

        return array_values(array_unique(array_filter([$folded, $core, self::reduce($folded), self::reduce($core)])));
    }

    /**
     * Whether two forms have the same two or more meaningful words, in any
     * order and form: "forklift operation" and "operate forklift".
     *
     * @param  list<string>  $typedForms
     * @param  list<string>  $labelForms
     */
    private static function sameWords(array $typedForms, array $labelForms): bool
    {
        $stems = function (string $form): string {
            $stems = Words::contentStems($form);
            sort($stems);

            return count($stems) >= 2 ? implode(' ', $stems) : '';
        };

        $typed = array_filter(array_map($stems, $typedForms));

        return $typed !== [] && array_intersect($typed, array_filter(array_map($stems, $labelForms))) !== [];
    }
}

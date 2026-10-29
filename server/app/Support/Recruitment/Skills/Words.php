<?php

namespace App\Support\Recruitment\Skills;

use Illuminate\Support\Str;

/**
 * How the ATS turns text into comparable words (ADR 0097). One set of rules
 * for the catalogue, a posting's skills and a candidate's résumé, so "Node.js",
 * "NodeJS" and "node js" can never disagree.
 */
final class Words
{
    /**
     * Characters résumés use to separate list items that transliteration would
     * otherwise drop, so "Python · R" keeps its separator.
     */
    private const SEPARATORS = ['•', '·', '▪', '●', '◦', '‣', '∙', '■', '□', '➢', '➤', '►', '✓', '✔', '|'];

    /** Endings removed when words are compared loosely, longest first. */
    private const SUFFIXES = [
        'izations' => 'ize', 'isations' => 'ize', 'ization' => 'ize', 'isation' => 'ize',
        'ational' => '', 'ations' => '', 'ation' => '',
        'ments' => '', 'ment' => '', 'ings' => '', 'ing' => '',
        'ies' => 'y', 'ied' => 'y', 'ates' => '', 'ate' => '',
        'ers' => '', 'er' => '', 'ed' => '', 'es' => '', 's' => '',
    ];

    /** Words that carry no meaning in a skill's name ("Strong knowledge of payroll"). */
    public const FILLER = [
        'a', 'an', 'and', 'or', 'of', 'the', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'from', 'as',
        'is', 'are', 'be', 'using', 'use', 'skill', 'skills', 'knowledge', 'experience', 'experienced',
        'proficient', 'proficiency', 'strong', 'good', 'excellent', 'basic', 'advanced', 'intermediate',
        'solid', 'working', 'familiar', 'familiarity', 'hands', 'understanding', 'ability', 'able',
        'must', 'preferred', 'plus', 'expert', 'expertise', 'background',
    ];

    /** Words that qualify a skill a posting asks for without changing which skill it is. */
    public const QUALIFIERS = [
        'advanced', 'basic', 'intermediate', 'strong', 'good', 'excellent', 'solid', 'proven', 'deep',
        'hands on', 'working knowledge of', 'knowledge of', 'knowledge in', 'experience with',
        'experience in', 'experienced in', 'proficient in', 'proficient with', 'proficiency in',
        'proficiency with', 'expert in', 'expertise in', 'familiarity with', 'familiar with',
        'understanding of',
    ];

    /** Words that trail a skill's name without changing which skill it is ("React development"). */
    public const GENERIC = [
        'skill', 'skills', 'programming', 'development', 'developer', 'language', 'languages',
        'framework', 'frameworks', 'experience', 'proficiency', 'knowledge', 'tool', 'tools',
        'software', 'platform', 'platforms', 'expertise', 'certified', 'certification',
    ];

    /**
     * Plain ASCII with the original capitals, list separators kept as `|`.
     */
    public static function ascii(string $text): string
    {
        return Str::ascii(str_replace(self::SEPARATORS, ' | ', $text));
    }

    /**
     * Lower-case words separated by single spaces. The symbols that are part of
     * a skill's name survive as words (C++ → cplusplus, C# → csharp, .NET →
     * dotnet); every other mark separates words, so "node.js" is "node js".
     */
    public static function fold(string $text): string
    {
        return self::foldAscii(self::ascii($text));
    }

    /**
     * {@see fold()} for text already passed through {@see ascii()}.
     */
    public static function foldAscii(string $ascii): string
    {
        // An e-mail address says nothing about skills ("juan.react@…").
        $text = strtolower((string) preg_replace('/\S+@\S+\.\S+/', ' ', $ascii));

        $text = (string) preg_replace(
            ['/(?<![a-z0-9])c\+\+/', '/(?<![a-z0-9])c#/', '/(?<![a-z0-9])f#/', '/\b(asp|vb|ado)\.net\b/', '/(?<![a-z0-9])\.net\b/', '/&/'],
            [' cplusplus ', ' csharp ', ' fsharp ', '$1 dotnet', ' dotnet ', ' and '],
            $text,
        );

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /**
     * The words of a text, folded.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $folded = self::fold($text);

        return $folded === '' ? [] : explode(' ', $folded);
    }

    /**
     * A word with its endings removed, for loose comparison: "managed",
     * "managing", "manager" and "management" all become "manag";
     * "accredited" and "accreditation" both "accredit". Deliberately light —
     * it only ever compares a word with another word.
     */
    public static function stem(string $word): string
    {
        if (strlen($word) <= 3 || ! ctype_alpha($word)) {
            return $word;
        }

        // Two passes, so "engineering" and "engineer" meet at "engin".
        for ($pass = 0; $pass < 2; $pass++) {
            $before = $word;

            foreach (self::SUFFIXES as $suffix => $replacement) {
                if (! str_ends_with($word, $suffix) || strlen($word) - strlen($suffix) < 3) {
                    continue;
                }

                // "process", "status" and "analysis" are not plurals.
                if ($suffix === 's' && preg_match('/(ss|us|is)$/', $word)) {
                    break;
                }

                $word = substr($word, 0, -strlen($suffix)).$replacement;
                break;
            }

            if (strlen($word) > 3 && str_ends_with($word, 'e')) {
                $word = substr($word, 0, -1);
            }

            if (preg_match('/([bdfgklmnprt])\1$/', $word)) {
                $word = substr($word, 0, -1);
            }

            // "communicating" → "communicat" → "communic", as "communication".
            if (strlen($word) >= 6 && str_ends_with($word, 'at')) {
                $word = substr($word, 0, -2);
            }

            if ($word === $before) {
                break;
            }
        }

        return $word;
    }

    /**
     * The meaningful words of a skill's name, stemmed ("Strong knowledge of
     * project management" → ["project", "manag"]).
     *
     * @return list<string>
     */
    public static function contentStems(string $text): array
    {
        $words = array_filter(self::tokens($text), fn (string $word): bool => ! in_array($word, self::FILLER, true));

        return array_values(array_unique(array_map(self::stem(...), $words)));
    }

    /**
     * A posting's skill without the words around it that don't change which
     * skill it is: "Advanced Excel skills" → "excel".
     */
    public static function core(string $folded): string
    {
        $qualifiers = implode('|', array_map(fn (string $q): string => preg_quote($q, '/'), self::QUALIFIERS));
        $generic = implode('|', array_map(fn (string $g): string => preg_quote($g, '/'), self::GENERIC));

        $core = (string) preg_replace("/^(?:(?:{$qualifiers})\\s+)+/", '', $folded);
        $core = (string) preg_replace("/(?:\\s+(?:{$generic}))+$/", '', $core);

        return trim($core);
    }
}

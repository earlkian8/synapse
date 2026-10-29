<?php

namespace App\Support\Recruitment\Skills;

/**
 * One piece of a candidate's text (a résumé, a headline, a cover note),
 * indexed once so every skill can be looked for cheaply (ADR 0097).
 *
 * A skill is found as whole words: "Java" is not in "JavaScript", and "Excel"
 * is not in "excellent". Spacing and punctuation don't matter ("Node.js" is
 * in "NodeJS" and "node js"), and a version doesn't hide a skill ("PHP8",
 * "HTML5").
 */
final class SkillText
{
    /** @var list<string> */
    private array $tokens;

    /** @var array<string, list<int>> */
    private array $positions = [];

    /** @var array<string, true> words, words without a version, and neighbouring words written together */
    private array $forms = [];

    /** @var array<string, list<int>>|null */
    private ?array $stemPositions = null;

    /** @var array<string, list<string>>|null unique words by first letter, for spotting slips */
    private ?array $byInitial = null;

    /**
     * @param  list<string>  $ignored  lower-case words never read as a skill — the candidate's own name, so "Ruby Santos" knows no Ruby
     */
    private function __construct(private readonly string $ascii, private readonly array $ignored)
    {
        $folded = Words::foldAscii($ascii);
        $this->tokens = $folded === '' ? [] : explode(' ', $folded);

        foreach ($this->tokens as $index => $token) {
            $this->positions[$token][] = $index;
            $this->forms[$token] = true;

            // "php8", "html5", "python3" → also "php", "html", "python".
            if (preg_match('/^([a-z]{2,})\d+$/', $token, $match) === 1) {
                $this->forms[$match[1]] = true;
            }

            // "Type Script", "node js" → also "typescript", "nodejs".
            $next = $this->tokens[$index + 1] ?? null;

            if ($next !== null && strlen($token) >= 3 && strlen($next) >= 2) {
                $this->forms[$token.$next] = true;
            }
        }
    }

    /**
     * @param  list<string>  $ignored
     */
    public static function of(?string $text, array $ignored = []): self
    {
        return new self(Words::ascii((string) $text), array_map('strtolower', $ignored));
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    /**
     * How this text writes the skill, if it does: the spelling found ("JS"
     * for JavaScript), or null.
     */
    public function mentions(Skill $skill): ?string
    {
        if ($this->tokens === []) {
            return null;
        }

        foreach ($skill->spellings as $spelling) {
            if ($this->has($spelling['tokens'], $spelling['joined'])) {
                return $spelling['text'];
            }
        }

        foreach ($skill->cased as $word) {
            if (! $this->isIgnored($word) && preg_match($this->casedPattern($word), $this->ascii) === 1) {
                return $word;
            }
        }

        foreach ($skill->listed as $word) {
            if (! $this->isIgnored($word) && preg_match($this->listedPattern($word), $this->ascii) === 1) {
                return $word;
            }
        }

        return null;
    }

    /**
     * A word one letter away from the skill's name ("Javscript",
     * "Kubernates"), for the spellings that can't be mistaken for another
     * word. Returns the word as the text has it.
     */
    public function misspells(Skill $skill, SkillCatalogue $catalogue): ?string
    {
        foreach ($skill->spellings as $spelling) {
            if (! $spelling['fuzzy']) {
                continue;
            }

            $target = $spelling['joined'];

            foreach ($this->initials()[$target[0]] ?? [] as $word) {
                if (abs(strlen($word) - strlen($target)) > 1 || $word === $target || $catalogue->isKnownWord($word)) {
                    continue;
                }

                if (levenshtein($word, $target) === 1) {
                    return $word;
                }
            }
        }

        return null;
    }

    /**
     * Where every given stem appears close together, in any order and any
     * form ("project management" in "managed several projects"): within
     * `$slack` words more than the phrase itself. Returns the words as the
     * text has them, or null.
     *
     * @param  list<string>  $stems
     */
    public function phrase(array $stems, int $slack = 3): ?string
    {
        if ($stems === [] || $this->tokens === []) {
            return null;
        }

        $positions = $this->stemPositions();

        foreach ($stems as $stem) {
            if (! isset($positions[$stem])) {
                return null;
            }
        }

        if (count($stems) === 1) {
            return $this->tokens[$positions[$stems[0]][0]];
        }

        // Anchor on the rarest word and look for the rest within a window.
        usort($stems, fn (string $a, string $b): int => count($positions[$a]) <=> count($positions[$b]));
        $window = count($stems) + $slack;

        foreach ($positions[$stems[0]] as $anchor) {
            $span = [$anchor, $anchor];

            foreach (array_slice($stems, 1) as $stem) {
                $near = null;

                foreach ($positions[$stem] as $position) {
                    if (abs($position - $anchor) <= $window) {
                        $near = $position;
                        break;
                    }
                }

                if ($near === null) {
                    continue 2;
                }

                $span = [min($span[0], $near), max($span[1], $near)];
            }

            return implode(' ', array_slice($this->tokens, $span[0], min($span[1] - $span[0] + 1, 8)));
        }

        return null;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function has(array $tokens, string $joined): bool
    {
        if (isset($this->forms[$joined])) {
            return true;
        }

        if (count($tokens) === 1) {
            return false;
        }

        foreach ($this->positions[$tokens[0]] ?? [] as $start) {
            foreach ($tokens as $offset => $token) {
                if (($this->tokens[$start + $offset] ?? null) !== $token) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    private function isIgnored(string $word): bool
    {
        return in_array(strtolower($word), $this->ignored, true);
    }

    /**
     * The word with these exact capitals, as a whole word: "Excel" but not
     * "excel at" or "Excellent", "ML" but not "ml".
     */
    private function casedPattern(string $word): string
    {
        return '/(?<![A-Za-z0-9_])'.preg_quote($word, '/').'(?![A-Za-z0-9_#+])/';
    }

    /**
     * The word as an item of a list — after a line start, a bullet, a comma,
     * a slash, "and"/"or" — and before the next separator or the line's end:
     * "Python, R, SQL" has R; "Juan R. Cruz" and "go the extra mile" don't.
     * One letter must match its case and may not be followed by a full stop
     * (an initial). The text is ASCII by now, so a dash is always "-".
     */
    private function listedPattern(string $word): string
    {
        $single = strlen($word) === 1;
        $quoted = preg_quote($word, '/');
        $before = '(?:^[ \t]*(?:[-*+>][ \t]*)?|[,;\/|(\[:\t][ \t]*|[ \t]-[ \t]+|\b(?:and|or)[ \t]+)';
        $after = '(?=[ \t]*(?:$|[,;\/|)\]\t]|[ \t](?:and|or)\b|[ \t]-[ \t]'.($single ? '' : '|\.(?:\s|$)').'))';

        return '/'.$before.$quoted.$after.'/m'.($single ? '' : 'i');
    }

    /**
     * @return array<string, list<int>>
     */
    private function stemPositions(): array
    {
        if ($this->stemPositions === null) {
            $this->stemPositions = [];

            foreach ($this->tokens as $index => $token) {
                $this->stemPositions[Words::stem($token)][] = $index;
            }
        }

        return $this->stemPositions;
    }

    /**
     * @return array<string, list<string>>
     */
    private function initials(): array
    {
        if ($this->byInitial === null) {
            $this->byInitial = [];

            foreach (array_keys($this->positions) as $word) {
                $word = (string) $word;

                if (strlen($word) >= 5 && ctype_alpha($word)) {
                    $this->byInitial[$word[0]][] = $word;
                }
            }
        }

        return $this->byInitial;
    }
}

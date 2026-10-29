<?php

namespace App\Support\Recruitment\Skills;

/**
 * One skill the catalogue knows (ADR 0097), with every way of writing it
 * already folded for matching.
 */
final class Skill
{
    /**
     * @param  list<array{text: string, tokens: list<string>, joined: string, fuzzy: bool}>  $spellings  the name and its aliases
     * @param  list<string>  $cased  spellings that count only with these capitals
     * @param  list<string>  $listed  spellings that count only as a list item
     * @param  list<string>  $implies  keys of the skills this one proves directly
     * @param  list<string>  $parts  keys of the skills this one is made of
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $spellings,
        public readonly array $cased,
        public readonly array $listed,
        public readonly array $implies,
        public readonly array $parts,
        public readonly bool $umbrella,
    ) {}

    /**
     * Build a skill from its catalogue entry.
     *
     * @param  array{name: string, aliases?: list<string>, cased?: list<string>, listed?: list<string>, implies?: list<string>, parts?: list<string>, umbrella?: bool}  $entry
     */
    public static function fromEntry(string $key, array $entry): self
    {
        $spellings = [];

        // A name that is also an everyday word ("Excel", "Go", "R") counts only
        // the way its `cased` and `listed` spellings say, never freely.
        $guarded = array_map('strtolower', [...($entry['cased'] ?? []), ...($entry['listed'] ?? [])]);

        foreach ([$entry['name'], ...($entry['aliases'] ?? [])] as $text) {
            $tokens = Words::tokens($text);

            if ($tokens === [] || in_array(implode(' ', $tokens), $guarded, true)) {
                continue;
            }

            $joined = implode('', $tokens);

            // A slip of one letter is forgiven only where it can't make another
            // word: long names, and names written with inner capitals
            // ("PostgreSQL"). "welding" is one letter from "wedding".
            $fuzzy = count($tokens) === 1
                && (strlen($joined) >= 10 || (strlen($joined) >= 6 && preg_match('/[a-z][A-Z]/', $text) === 1));

            $spellings[$joined] ??= ['text' => $text, 'tokens' => $tokens, 'joined' => $joined, 'fuzzy' => $fuzzy];
        }

        return new self(
            key: $key,
            name: $entry['name'],
            spellings: array_values($spellings),
            cased: array_values($entry['cased'] ?? []),
            listed: array_values($entry['listed'] ?? []),
            implies: array_values(array_unique([...($entry['implies'] ?? []), ...($entry['parts'] ?? [])])),
            parts: array_values($entry['parts'] ?? []),
            umbrella: (bool) ($entry['umbrella'] ?? false),
        );
    }

    /**
     * The aliases as they were written, for showing what else counts.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach ([...array_column($this->spellings, 'text'), ...$this->cased, ...$this->listed] as $text) {
            $aliases[strtolower($text)] ??= $text;
        }

        unset($aliases[strtolower($this->name)]);

        return array_values($aliases);
    }
}

<?php

namespace App\Services\Assistant\Retrieval;

use App\Services\Assistant\Security\UntrustedText;

/**
 * One module's contribution to a {@see ContextBrief} — the slice of a subject's
 * record that module owns, already reduced to lines a model can read.
 *
 * A section is written, not queried, at read time: the module has already
 * checked the asker's permission and applied whatever disclosure policy governs
 * its data, so everything here is safe to put in front of the model. `source` is
 * also what the chat timeline names, so it should read as a place a person could
 * go and check — "Attendance (last 30 days)", not "attendance_records".
 *
 * Every line is cleaned on the way in ({@see UntrustedText}): a module composes
 * its lines from names, notes and remarks other people wrote, and none of that
 * may break a line, forge the data fence, or run on for a page. Cleaning here —
 * rather than in each module — is what covers the next module too.
 */
final class ContextSection
{
    public readonly string $source;

    /** @var list<string> */
    public readonly array $lines;

    /** A caveat the model should carry into its answer, if any. */
    public readonly ?string $note;

    /**
     * @param  list<string|null>  $lines
     */
    public function __construct(string $source, array $lines, ?string $note = null)
    {
        $this->source = UntrustedText::clean($source, 80) ?? 'Record';
        $this->lines = array_values(array_filter(array_map(
            fn (?string $line): ?string => UntrustedText::clean($line, UntrustedText::LINE),
            $lines,
        ), fn (?string $line): bool => $line !== null));
        $this->note = UntrustedText::clean($note, UntrustedText::LINE);
    }

    /**
     * @param  list<string|null>  $lines
     */
    public static function of(string $source, array $lines, ?string $note = null): self
    {
        return new self($source, $lines, $note);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function toPrompt(): string
    {
        $body = implode("\n", array_map(fn (string $line): string => '- '.$line, $this->lines));

        if ($this->note !== null) {
            $body .= "\n- Note: ".$this->note;
        }

        return "## {$this->source}\n{$body}";
    }
}

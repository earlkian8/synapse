<?php

namespace App\Services\Assistant\Security;

/**
 * The delimiter that brackets untrusted data in the system instruction, drawn
 * fresh for every turn ("spotlighting").
 *
 * Retrieved record text is placed between an opening and a closing marker that
 * carry a random tag, and the instruction tells the model that nothing between
 * them is ever a request. Two properties make the fence hold:
 *
 * - **It cannot be guessed.** The tag is 64 random bits, new every turn, so a
 *   record written last week cannot contain this turn's closing marker.
 * - **It cannot be typed.** {@see UntrustedText} breaks up the `<<` / `>>` runs
 *   the markers are made of wherever they appear in data, so even a lucky guess
 *   could not be written out whole.
 */
final class PromptFence
{
    private function __construct(public readonly string $tag) {}

    public static function fresh(): self
    {
        return new self(bin2hex(random_bytes(8)));
    }

    public function open(): string
    {
        return "<<<UNTRUSTED-DATA {$this->tag}>>>";
    }

    public function close(): string
    {
        return "<<<END-UNTRUSTED-DATA {$this->tag}>>>";
    }

    /**
     * Bracket a block of (already cleaned) data.
     */
    public function wrap(string $body): string
    {
        return $this->open()."\n".$body."\n".$this->close();
    }
}

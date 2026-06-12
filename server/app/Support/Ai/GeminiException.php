<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * Thrown when a Gemini API call fails, carrying the HTTP status so callers can
 * distinguish transient busy/quota responses (429/503) from real errors.
 */
class GeminiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    /** Whether this is a transient rate-limit / overload the user can retry. */
    public function isBusy(): bool
    {
        return in_array($this->status, [429, 503], true);
    }
}

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

    /** A 429: the API quota / rate limit was exceeded (free tier is small). */
    public function isQuota(): bool
    {
        return $this->status === 429;
    }

    /** A 503: the model is momentarily overloaded (not a quota issue). */
    public function isOverloaded(): bool
    {
        return $this->status === 503;
    }

    /**
     * The server-suggested retry delay in seconds, if the error carried one
     * (e.g. "Please retry in 12.5s").
     */
    public function retryAfterSeconds(): ?int
    {
        if (preg_match('/retry in ([0-9.]+)s/i', $this->getMessage(), $m) === 1) {
            return (int) ceil((float) $m[1]);
        }

        return null;
    }
}

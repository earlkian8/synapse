<?php

namespace App\Support\Ai;

use App\Services\Assistant\Security\UntrustedText;

/**
 * Reading a one-shot, strict-JSON Gemini answer, and saying why there is none — the
 * plumbing every "insights" feature needs around its single model call. The older
 * insight classes (applicants, reports, training, performance, award citations) keep
 * private copies of the same steps; new ones use this.
 */
final class GeminiReply
{
    /**
     * The text parts of a response, joined.
     *
     * @param  array<string, mixed>  $response
     */
    public static function text(array $response): string
    {
        $parts = data_get($response, 'candidates.0.content.parts', []);

        if (! is_array($parts)) {
            return '';
        }

        return trim(implode("\n", array_map(
            fn (mixed $part): string => is_array($part) && isset($part['text']) ? (string) $part['text'] : '',
            $parts,
        )));
    }

    /**
     * The JSON object in a response, tolerating code fences and prose around it.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    public static function json(array $response): ?array
    {
        $text = self::text($response);

        if ($text === '') {
            return null;
        }

        $text = preg_replace('/^```(?:json)?|```$/m', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * One field of the answer as plain, bounded text — whatever shape it came in.
     * The answer is rendered to people, so it is cleaned like any untrusted text.
     */
    public static function line(mixed $value, int $limit = UntrustedText::LINE): string
    {
        return UntrustedText::clean(is_scalar($value) ? (string) $value : null, $limit) ?? '';
    }

    /**
     * A list field of the answer as clean, non-empty lines, at most `$max`.
     *
     * @return list<string>
     */
    public static function lines(mixed $value, int $max): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => self::line($item),
            array_slice(array_values($value), 0, $max),
        )));
    }

    /**
     * Why there is no answer, as the panel shows it.
     *
     * @return array{available: false, reason: string, retryable: bool}
     */
    public static function unavailable(string $reason, bool $retryable): array
    {
        return ['available' => false, 'reason' => $reason, 'retryable' => $retryable];
    }

    /**
     * A failed call in the reader's words: whether to wait, and roughly how long.
     */
    public static function failure(GeminiException $exception): string
    {
        if ($exception->isQuota()) {
            $retry = $exception->retryAfterSeconds();

            return $retry !== null
                ? "The AI service is rate-limited. Try again in about {$retry}s."
                : 'The AI service hit its rate limit. Try again in a moment.';
        }

        if ($exception->isOverloaded()) {
            return 'The AI service is briefly overloaded. Try again in a moment.';
        }

        if ($exception->isTimeout()) {
            return 'The AI service took too long to answer. Try again shortly.';
        }

        return 'The AI service returned an error. Try again shortly.';
    }
}

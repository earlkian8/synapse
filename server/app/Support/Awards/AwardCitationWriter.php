<?php

namespace App\Support\Awards;

use App\Models\AwardType;
use App\Models\Employee;
use App\Support\Ai\GeminiClient;
use App\Support\Ai\GeminiException;
use App\Support\Recruitment\ApplicantInsights;
use Throwable;

/**
 * LLM drafting of an award citation — the short "reason" text on a recognition.
 * Given the employee, the award type, and the nominee's real scoring signals
 * (from {@see AwardNominator}), it returns one warm, specific, 1–2 sentence
 * citation grounded in those signals. A draft the granter edits, never
 * persisted on its own.
 *
 * Cost-disciplined like {@see ApplicantInsights}: one
 * model call per request, strict-JSON out, and graceful, retryable degradation
 * on quota / overload.
 */
class AwardCitationWriter
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /** Whether citation drafting is available (an API key is configured). */
    public function enabled(): bool
    {
        return $this->gemini->configured();
    }

    /**
     * Draft one citation from the nominee's grounded signals.
     *
     * @param  list<array{key: string, label: string, points: int, max: int, detail: string}>  $components
     * @return array<string, mixed>
     */
    public function draft(Employee $employee, AwardType $type, array $components): array
    {
        if (! $this->gemini->configured()) {
            return $this->unavailable('AI drafting isn’t configured. Set GEMINI_API_KEY to enable it.', retryable: false);
        }

        $parts = [['text' => $this->digest($employee, $type, $components)]];

        try {
            $response = $this->gemini->generate(
                [['role' => 'user', 'parts' => $parts]],
                [],
                $this->systemInstruction(),
            );
        } catch (GeminiException $exception) {
            return $this->unavailable($this->friendlyError($exception), retryable: $exception->isBusy());
        } catch (Throwable) {
            return $this->unavailable('Couldn’t reach the AI service. Try again shortly.', retryable: true);
        }

        $parsed = $this->parseJson($this->extractText($response));
        $citation = trim((string) ($parsed['citation'] ?? ''));

        if ($citation === '') {
            return $this->unavailable('The AI response couldn’t be parsed. Try again.', retryable: true);
        }

        return ['available' => true, 'citation' => $citation];
    }

    /**
     * The model's brief: a warm, specific citation grounded only in the digest.
     */
    private function systemInstruction(): string
    {
        return <<<'PROMPT'
        You write award citations inside an HR ERP. You are given ONE employee,
        ONE award, and the grounded signals behind their nomination (appraisal
        score, attendance, trainings, tenure, time since last recognition). Write
        the short citation that will appear on the recognition.

        Respond with STRICT minified JSON and nothing else, in this exact shape:
        {"citation":"..."}

        Rules:
        - 1–2 sentences, at most ~40 words. Warm, specific and professional.
        - Ground it in the signals given (e.g. their attendance, appraisal, or
          tenure) — never invent projects, numbers, or events.
        - Write about the employee in the third person by first name.
        - No emojis, no exclamation overload (one at most), no markdown.
        PROMPT;
    }

    /**
     * The compact, model-facing digest of who, what award, and why.
     *
     * @param  list<array{key: string, label: string, points: int, max: int, detail: string}>  $components
     */
    private function digest(Employee $employee, AwardType $type, array $components): string
    {
        $lines = [];

        $lines[] = 'EMPLOYEE: '.$employee->full_name;

        if ($employee->relationLoaded('position') && $employee->position) {
            $lines[] = 'ROLE: '.$employee->position->title;
        }

        if ($employee->relationLoaded('department') && $employee->department) {
            $lines[] = 'DEPARTMENT: '.$employee->department->name;
        }

        $lines[] = '';
        $lines[] = 'AWARD: '.$type->name;

        if (filled($type->description)) {
            $lines[] = 'AWARD MEANING: '.$type->description;
        }

        $lines[] = '';
        $lines[] = 'NOMINATION SIGNALS:';

        foreach ($components as $component) {
            $lines[] = '  - '.$component['label'].': '.$component['detail'];
        }

        return implode("\n", $lines);
    }

    /**
     * Concatenate the text parts of a Gemini response.
     *
     * @param  array<string, mixed>  $response
     */
    private function extractText(array $response): string
    {
        $parts = data_get($response, 'candidates.0.content.parts', []);

        if (! is_array($parts)) {
            return '';
        }

        $texts = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $texts[] = (string) $part['text'];
            }
        }

        return trim(implode("\n", $texts));
    }

    /**
     * Parse the model's JSON, tolerating code fences and surrounding prose.
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
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
     * @return array<string, mixed>
     */
    private function unavailable(string $reason, bool $retryable): array
    {
        return ['available' => false, 'reason' => $reason, 'retryable' => $retryable];
    }

    private function friendlyError(GeminiException $exception): string
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

        return 'The AI service returned an error. Try again shortly.';
    }
}

<?php

namespace App\Support\Training;

use App\Models\TrainingProgram;
use App\Support\Ai\GeminiClient;
use App\Support\Ai\GeminiException;
use App\Support\Recruitment\ApplicantInsights;
use Throwable;

/**
 * LLM decision support for a training program. It compiles a compact digest —
 * the program and provider, its schedule and seat usage, the roster outcomes
 * (how many enrolled / completed / dropped), the completion rate, the average
 * score, and the people who look at-risk (still enrolled after the program ended
 * or scoring low) — and returns a grounded read of the program's effectiveness:
 * what is working, the concerns, concrete recommendations, and who to follow up
 * with.
 *
 * Cost-disciplined like {@see ApplicantInsights}: one
 * model call per request, strict-JSON out, and graceful, retryable degradation on
 * quota / overload. No documents are attached — only the digest text.
 */
class TrainingInsights
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /** Whether AI insights can be generated at all (an API key is configured). */
    public function enabled(): bool
    {
        return $this->gemini->configured();
    }

    /**
     * Generate effectiveness insights for one program. `$digest` is the
     * pre-compiled analytics + roster summary from the controller.
     *
     * @param  array<string, mixed>  $analytics
     * @param  list<array{name: string, status: string, score: float|null}>  $roster
     * @return array<string, mixed>
     */
    public function generate(TrainingProgram $program, array $analytics, array $roster): array
    {
        if (! $this->gemini->configured()) {
            return $this->unavailable('AI insights aren’t configured. Set GEMINI_API_KEY to enable them.', retryable: false);
        }

        $parts = [['text' => $this->digest($program, $analytics, $roster)]];

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

        if ($parsed === null) {
            return $this->unavailable('The AI response couldn’t be parsed. Try again.', retryable: true);
        }

        return [
            'available' => true,
            'headline' => (string) ($parsed['headline'] ?? 'Training insights'),
            'summary' => (string) ($parsed['summary'] ?? ''),
            'whats_working' => $this->stringList($parsed['whats_working'] ?? null),
            'concerns' => $this->stringList($parsed['concerns'] ?? null),
            'recommendations' => $this->stringList($parsed['recommendations'] ?? null),
            'follow_up' => $this->stringList($parsed['follow_up'] ?? null),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The model's brief: an L&D partner, grounded only in the digest, answering
     * with strict JSON.
     */
    private function systemInstruction(): string
    {
        return <<<'PROMPT'
        You are a learning & development partner embedded in an HR ERP, assessing
        ONE training program. You are given a digest: the program and provider, its
        schedule and lifecycle status, seat usage, roster outcomes (enrolled /
        completed / dropped counts), the completion rate, the average completion
        score, and a roster of participants with each one's status and score. Base
        everything on this digest.

        Respond with STRICT minified JSON and nothing else, in this exact shape:
        {"headline":"...","summary":"...","whats_working":["..."],"concerns":["..."],"recommendations":["..."],"follow_up":["..."]}

        Rules:
        - "headline": a short verdict (max ~8 words), e.g. "Strong completion, three learners stalled".
        - "summary": 2-3 sentences on how effective this program looks given its completion rate, scores and dropouts. Be specific and grounded.
        - "whats_working": 1-3 concrete positives (e.g. high completion, strong scores). Use an empty list if there is genuinely nothing positive yet.
        - "concerns": 1-4 honest risks — low completion, dropouts, weak scores, or people still enrolled after the program ended.
        - "recommendations": 2-4 specific, actionable steps for the L&D team or managers (e.g. follow-up sessions, re-scheduling, capacity changes).
        - "follow_up": 0-4 named participants who need attention (dropouts or low scorers), phrased as "<name> — <why>". Use only names present in the digest.
        - Ground every claim in the digest. Do not invent people, scores or events. No markdown, no preamble, no code fences.
        PROMPT;
    }

    /**
     * Compile the compact, model-facing text digest.
     *
     * @param  array<string, mixed>  $analytics
     * @param  list<array{name: string, status: string, score: float|null}>  $roster
     */
    private function digest(TrainingProgram $program, array $analytics, array $roster): string
    {
        $lines = [];

        $lines[] = 'PROGRAM: '.$program->name;
        $lines[] = 'PROVIDER: '.($program->provider ?? 'In-house');
        $lines[] = 'STATUS: '.$program->status();
        $lines[] = 'SCHEDULE: '.$this->schedule($program);
        $lines[] = 'CAPACITY: '.($program->capacity === null ? 'uncapped' : (string) $program->capacity);

        $lines[] = '';
        $lines[] = 'ROSTER OUTCOMES:';
        $lines[] = '  - Total enrolled: '.$analytics['total'];
        $lines[] = '  - Completed: '.$analytics['completed'];
        $lines[] = '  - Still enrolled: '.$analytics['enrolled'];
        $lines[] = '  - Dropped: '.$analytics['dropped'];
        $lines[] = '  - Completion rate: '.($analytics['completion_rate'] === null ? 'n/a' : $analytics['completion_rate'].'%');
        $lines[] = '  - Average score: '.($analytics['average_score'] === null ? 'n/a' : $analytics['average_score'].' / 100');
        $lines[] = '  - Still enrolled after the program ended (at-risk): '.$analytics['at_risk'];

        if ($roster !== []) {
            $lines[] = '';
            $lines[] = 'PARTICIPANTS (status, score):';
            foreach ($roster as $person) {
                $score = $person['score'] === null ? 'no score' : rtrim(rtrim(number_format($person['score'], 2), '0'), '.').'%';
                $lines[] = '  - '.$person['name'].' — '.$person['status'].', '.$score;
            }
        }

        return implode("\n", $lines);
    }

    private function schedule(TrainingProgram $program): string
    {
        $start = $program->start_date?->toFormattedDateString();
        $end = $program->end_date?->toFormattedDateString();

        return match (true) {
            $start !== null && $end !== null => "{$start} to {$end}",
            $start !== null => "from {$start}",
            $end !== null => "until {$end}",
            default => 'self-paced',
        };
    }

    /**
     * Normalise a model-provided list into clean, non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            $value,
        )));
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

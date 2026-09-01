<?php

namespace App\Support\Reports;

use App\Support\Ai\GeminiClient;
use App\Support\Ai\GeminiException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Turns a report's figures into decision support with the LLM. It compiles a compact
 * digest of the report — its totals, its chart aggregates, the ML signals, a sample of
 * rows — and asks Gemini to answer the questions a manager actually has: what's
 * happening, what changed, why, and what to do about it.
 *
 * Cost-disciplined to the project's free-tier reality: exactly one model call per
 * request, no full row dumps (aggregates carry the signal), and graceful, retryable
 * degradation on quota/overload so the UI can offer "try again" rather than erroring.
 */
class ReportInsights
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /** Whether AI insights can be generated at all (an API key is configured). */
    public function enabled(): bool
    {
        return $this->gemini->configured();
    }

    /**
     * Generate decision-support insights for a report run.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{label: string, value: string}>  $summary
     * @param  list<array<string, mixed>>  $charts
     * @param  list<array<string, mixed>>  $signals
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function generate(Report $report, Collection $rows, array $summary, array $charts, array $signals, array $params): array
    {
        if (! $this->gemini->configured()) {
            return $this->unavailable('AI insights aren’t configured. Set GEMINI_API_KEY to enable them.', retryable: false);
        }

        try {
            $response = $this->gemini->generate(
                [['role' => 'user', 'parts' => [['text' => $this->digest($report, $rows, $summary, $charts, $signals, $params)]]]],
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
            'headline' => (string) ($parsed['headline'] ?? 'Insights'),
            'whats_happening' => (string) ($parsed['whats_happening'] ?? ''),
            'what_happened' => (string) ($parsed['what_happened'] ?? ''),
            'why' => (string) ($parsed['why'] ?? ''),
            'recommendations' => array_values(array_filter(array_map(
                fn ($item): string => trim((string) $item),
                is_array($parsed['recommendations'] ?? null) ? $parsed['recommendations'] : [],
            ))),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The model's brief: strict JSON, grounded only in the digest, manager-grade.
     */
    private function systemInstruction(): string
    {
        return <<<'PROMPT'
        You are a senior HR analytics advisor embedded in an HR ERP. You are given ONE
        report's digest: its filters, totals, chart aggregates, machine-learning model
        signals, and a small sample of rows. Produce concise, decision-grade analysis.

        Respond with STRICT minified JSON and nothing else, in this exact shape:
        {"headline": "...", "whats_happening": "...", "what_happened": "...", "why": "...", "recommendations": ["...", "..."]}

        Rules:
        - "headline": a short title (max ~8 words).
        - "whats_happening": the current state, 1-2 sentences, specific and numeric.
        - "what_happened": the notable change, trend or outlier in this data, 1-2 sentences.
        - "why": the likely drivers. Reference the ML signals (promotion /
          performance) when they are relevant.
        - "recommendations": 2-4 concrete next actions, each a single imperative sentence.
        - Ground every claim ONLY in the digest. Do not invent figures. No markdown, no
          preamble, no code fences.
        PROMPT;
    }

    /**
     * Compile the compact, model-facing digest.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{label: string, value: string}>  $summary
     * @param  list<array<string, mixed>>  $charts
     * @param  list<array<string, mixed>>  $signals
     * @param  array<string, mixed>  $params
     */
    private function digest(Report $report, Collection $rows, array $summary, array $charts, array $signals, array $params): string
    {
        $lines = [];
        $lines[] = "REPORT: {$report->name()} — {$report->description()}";

        if ($params !== []) {
            $filters = collect($params)
                ->map(fn ($value, string $key): string => "{$key}={$value}")
                ->implode(', ');
            $lines[] = "FILTERS: {$filters}";
        }

        $lines[] = 'ROW COUNT: '.$rows->count();

        $lines[] = 'TOTALS: '.collect($summary)
            ->map(fn (array $stat): string => "{$stat['label']}={$stat['value']}")
            ->implode(', ');

        foreach ($charts as $chart) {
            $points = $chart['type'] === 'donut' ? ($chart['segments'] ?? []) : ($chart['bars'] ?? []);
            $rendered = collect($points)
                ->map(fn (array $point): string => "{$point['label']}: {$point['value']}")
                ->implode(', ');
            $lines[] = "CHART [{$chart['title']}]: {$rendered}";
        }

        foreach ($signals as $signal) {
            $breakdown = collect($signal['breakdown'] ?? [])
                ->map(fn ($value, string $key): string => "{$key}={$value}")
                ->implode(', ');
            $lines[] = "ML SIGNAL [{$signal['label']}]: {$signal['value']} ({$signal['detail']}); {$breakdown}";
        }

        // A small, representative sample — enough to ground the narrative without
        // shipping the whole table (or its cost) to the model.
        $sample = $rows->take(8)->map(fn (array $row): string => implode(' | ', array_map(
            fn ($value): string => (string) $value,
            $row,
        )));

        if ($sample->isNotEmpty()) {
            $lines[] = 'SAMPLE ROWS ('.$sample->count().' of '.$rows->count().'):';
            $lines[] = $sample->implode("\n");
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

        // Strip code fences, then isolate the outermost JSON object.
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

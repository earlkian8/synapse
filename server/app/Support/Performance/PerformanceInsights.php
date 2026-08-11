<?php

namespace App\Support\Performance;

use App\Models\PerformanceEvaluation;
use App\Models\PerformanceScore;
use App\Support\Ai\GeminiClient;
use App\Support\Ai\GeminiException;
use App\Support\Recruitment\ApplicantInsights;
use Throwable;

/**
 * LLM decision support for one employee's appraisal. It compiles a compact
 * digest — the employee and role, the review cycle, the framework it was
 * conducted under, the result in that company's own rating band, each line
 * scored on its own scale within its section, the evaluator's remarks, and the
 * employee's history across cycles — and returns a grounded managerial read:
 * strengths, development areas, concrete coaching actions, suggested goals for
 * next cycle, and a one-line recommendation.
 *
 * Cost-disciplined like {@see ApplicantInsights}: one
 * model call per request, strict-JSON out, and graceful, retryable degradation on
 * quota / overload. No employee documents are attached — only the digest text.
 */
class PerformanceInsights
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /** Whether AI insights can be generated at all (an API key is configured). */
    public function enabled(): bool
    {
        return $this->gemini->configured();
    }

    /**
     * Generate coaching-oriented insights for one evaluation. The evaluation is
     * expected to have `employee`, `period` and `scores` loaded.
     *
     * @param  list<array{period: string|null, percent: float, score: float, label: string|null, status: string}>  $history
     * @return array<string, mixed>
     */
    public function generate(PerformanceEvaluation $evaluation, array $history): array
    {
        if (! $this->gemini->configured()) {
            return $this->unavailable('AI insights aren’t configured. Set GEMINI_API_KEY to enable them.', retryable: false);
        }

        $parts = [['text' => $this->digest($evaluation, $history)]];

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
            'headline' => (string) ($parsed['headline'] ?? 'Performance insights'),
            'summary' => (string) ($parsed['summary'] ?? ''),
            'strengths' => $this->stringList($parsed['strengths'] ?? null),
            'development_areas' => $this->stringList($parsed['development_areas'] ?? null),
            'coaching_actions' => $this->stringList($parsed['coaching_actions'] ?? null),
            'suggested_goals' => $this->stringList($parsed['suggested_goals'] ?? null),
            'recommendation' => (string) ($parsed['recommendation'] ?? ''),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The model's brief: an experienced people manager, grounded only in the
     * digest, answering with strict JSON.
     */
    private function systemInstruction(): string
    {
        return <<<'PROMPT'
        You are an experienced people manager and HR business partner embedded in
        an HR ERP, reviewing ONE employee's performance appraisal. You are given a
        digest: the employee and their role, the review cycle, the appraisal
        framework used, the overall attainment (0–100) and the rating band this
        company reports it as, each criterion scored on its own scale with its
        weight and section, the evaluator's written remarks, and the employee's
        attainment history across past cycles. Base everything on this digest.
        Use the company's own band wording when you describe the result.

        Respond with STRICT minified JSON and nothing else, in this exact shape:
        {"headline":"...","summary":"...","strengths":["..."],"development_areas":["..."],"coaching_actions":["..."],"suggested_goals":["..."],"recommendation":"..."}

        Rules:
        - "headline": a short verdict (max ~8 words), e.g. "Strong delivery, coach on stakeholder comms".
        - "summary": 2-3 sentences on this cycle's performance and the trajectory vs. prior periods. Be specific and grounded.
        - "strengths": 2-4 concrete strengths, tied to the highest-scoring criteria or the remarks.
        - "development_areas": 2-4 honest gaps, tied to the lowest-scoring criteria. An empty list is rarely right.
        - "coaching_actions": 2-4 specific, actionable steps a manager can take to help this employee improve.
        - "suggested_goals": 2-4 measurable goals for the next review period, targeting the development areas.
        - "recommendation": one sentence on the overall next step (e.g. recognition, a development plan, a check-in cadence).
        - Ground every claim in the digest. Do not invent scores, projects, or events. No markdown, no preamble, no code fences.
        PROMPT;
    }

    /**
     * Compile the compact, model-facing text digest.
     *
     * @param  list<array{period: string|null, percent: float, score: float, label: string|null, status: string}>  $history
     */
    private function digest(PerformanceEvaluation $evaluation, array $history): string
    {
        $employee = $evaluation->employee;
        $lines = [];

        $lines[] = 'EMPLOYEE: '.($employee?->full_name ?? 'Unknown');
        if ($employee?->relationLoaded('position') && $employee->position) {
            $lines[] = 'ROLE: '.$employee->position->title;
        }
        if ($employee?->relationLoaded('department') && $employee->department) {
            $lines[] = 'DEPARTMENT: '.$employee->department->name;
        }

        $lines[] = '';
        $lines[] = 'REVIEW CYCLE: '.($evaluation->period?->name ?? 'Unknown cycle');
        $lines[] = 'FRAMEWORK: '.($evaluation->template_name ?? 'Standard');
        $lines[] = 'STATUS: '.$evaluation->status;
        $lines[] = 'OVERALL ATTAINMENT: '.($evaluation->overall_percent !== null
            ? number_format((float) $evaluation->overall_percent, 1).' / 100'
                .($evaluation->result_label ? ' — rated "'.$evaluation->result_label.'"' : '')
            : 'not yet scored');

        $lines[] = 'RATING MODEL: '.implode(', ', array_map(
            fn (array $band): string => $band['label'].' ≥ '.rtrim(rtrim(number_format($band['min_percent'], 1), '0'), '.').'%',
            $evaluation->bandList(),
        ));

        $scores = $evaluation->relationLoaded('scores') ? $evaluation->scores : collect();

        if ($scores->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'SCORECARD (each criterion on its own scale, grouped by section):';

            foreach ($scores->groupBy('section_key') as $section) {
                $first = $section->first();
                $lines[] = '  ['.($first->section_name ?? 'Performance criteria')
                    .' — '.rtrim(rtrim((string) $first->section_weight, '0'), '.').'% of the appraisal]';

                foreach ($section as $score) {
                    $lines[] = '    - '.$score->label.' (weight '.rtrim(rtrim((string) $score->weight, '0'), '.').'%): '
                        .$this->displayScore($score)
                        .($score->remarks ? ' — '.$this->trim($score->remarks, 200) : '');
                }
            }
        }

        if (filled($evaluation->remarks)) {
            $lines[] = '';
            $lines[] = 'EVALUATOR REMARKS: '.$this->trim($evaluation->remarks, 1200);
        }

        if ($history !== []) {
            $lines[] = '';
            $lines[] = 'ATTAINMENT HISTORY (oldest → newest, 0–100):';
            foreach ($history as $point) {
                $lines[] = '  - '.($point['period'] ?? 'Cycle').': '.number_format($point['percent'] ?? 0, 1)
                    .(isset($point['label']) && $point['label'] !== null ? ' ('.$point['label'].')' : '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A line's raw score rendered in its own scale — "4 of 5", "82%",
     * "Proficient" — for the digest.
     */
    private function displayScore(PerformanceScore $score): string
    {
        if ($score->score === null) {
            return 'not scored';
        }

        $scale = $score->scale();
        $formatted = RatingScales::format((float) $score->score, $scale);

        return $scale['type'] === 'numeric'
            ? $formatted.' of '.RatingScales::format($scale['max'], $scale)
            : $formatted;
    }

    private function trim(?string $value, int $limit): string
    {
        return mb_strimwidth(trim((string) $value), 0, $limit, '…');
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

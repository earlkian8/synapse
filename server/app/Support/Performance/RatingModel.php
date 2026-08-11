<?php

namespace App\Support\Performance;

use Illuminate\Support\Str;

/**
 * A tenant's **rating model**: the ordered outcome bands an appraisal result is
 * reported in. This is the piece that stops "performance" meaning one hard-coded
 * 1–5 number — one company reports "Outstanding / Exceeds / Meets / Needs
 * Improvement", another reports "A / B / C / D / F", another reports three bands
 * against a target. All of them are the same thing: labelled cuts of attainment
 * on 0–100.
 *
 * A band is `{key, label, min_percent, description, tone}`. Bands are read from
 * the top down: a result belongs to the highest band whose `min_percent` it
 * reaches. `tone` is a semantic name (never a colour) so the UI keeps one palette.
 */
class RatingModel
{
    /** The semantic tones a band may carry, best outcome first. */
    public const TONES = ['positive', 'good', 'neutral', 'caution', 'critical'];

    /**
     * The five-band model a new framework starts from — the most common shape in
     * practice, and the one every pre-framework evaluation is read back into.
     *
     * @return list<array{key: string, label: string, min_percent: float, description: string|null, tone: string}>
     */
    public static function defaultBands(): array
    {
        return [
            ['key' => 'outstanding', 'label' => 'Outstanding', 'min_percent' => 90.0, 'description' => 'Consistently far beyond what the role asks for.', 'tone' => 'positive'],
            ['key' => 'exceeds', 'label' => 'Exceeds Expectations', 'min_percent' => 75.0, 'description' => 'Regularly delivers more than the role asks for.', 'tone' => 'good'],
            ['key' => 'meets', 'label' => 'Meets Expectations', 'min_percent' => 55.0, 'description' => 'Delivers the role in full.', 'tone' => 'neutral'],
            ['key' => 'needs_improvement', 'label' => 'Needs Improvement', 'min_percent' => 35.0, 'description' => 'Falls short in areas that matter to the role.', 'tone' => 'caution'],
            ['key' => 'unsatisfactory', 'label' => 'Unsatisfactory', 'min_percent' => 0.0, 'description' => 'Well below what the role requires.', 'tone' => 'critical'],
        ];
    }

    /**
     * The band a 0–100 attainment falls in — the highest one it reaches. Null
     * when the model is empty or the result sits below every band.
     *
     * @param  iterable<int, array<string, mixed>>  $bands
     * @return array{key: string, label: string, min_percent: float, description: string|null, tone: string}|null
     */
    public static function bandFor(?float $percent, iterable $bands): ?array
    {
        if ($percent === null) {
            return null;
        }

        foreach (self::normalize($bands) as $band) {
            if ($percent >= $band['min_percent']) {
                return $band;
            }
        }

        return null;
    }

    /**
     * The model in reading order — highest cut first — with every field filled
     * in. Bands arrive from JSON columns and from HR's own editing, so nothing
     * about their order or completeness can be assumed.
     *
     * @param  iterable<int, array<string, mixed>>  $bands
     * @return list<array{key: string, label: string, min_percent: float, description: string|null, tone: string}>
     */
    public static function normalize(iterable $bands): array
    {
        $normalized = [];

        foreach ($bands as $band) {
            if (! is_array($band) || ! isset($band['label']) || trim((string) $band['label']) === '') {
                continue;
            }

            $label = trim((string) $band['label']);

            $normalized[] = [
                'key' => (string) ($band['key'] ?? Str::slug($label, '_')),
                'label' => $label,
                'min_percent' => round((float) ($band['min_percent'] ?? 0), 2),
                'description' => isset($band['description']) && trim((string) $band['description']) !== ''
                    ? trim((string) $band['description'])
                    : null,
                'tone' => in_array($band['tone'] ?? null, self::TONES, true) ? (string) $band['tone'] : 'neutral',
            ];
        }

        usort($normalized, fn (array $a, array $b): int => $b['min_percent'] <=> $a['min_percent']);

        return $normalized;
    }
}

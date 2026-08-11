<?php

namespace App\Support\Performance;

use App\Models\RatingScale;

/**
 * The measurement side of an appraisal framework. A rating scale is one of three
 * things — a numeric range (`numeric`), a 0–100 percentage (`percentage`), or an
 * ordered set of named levels with behavioural anchors (`levels`) — and this is
 * the one place that knows how to read a raw score against any of them.
 *
 * Scales reach this class from three directions: a {@see RatingScale} row, the
 * snapshot columns frozen onto a score line, and HR's own JSON while editing.
 * Everything here therefore works on a plain array and tolerates a partial one.
 */
class RatingScales
{
    /** The kinds of instrument a scale can be. */
    public const TYPES = ['numeric', 'percentage', 'levels'];

    /**
     * The starter library a fresh tenant is given — the instruments a real HR
     * team reaches for, so the first framework can be built without designing a
     * scale first.
     *
     * @return list<array{name: string, description: string, type: string, min: float, max: float, step: float, levels: list<array{value: float, label: string, description: string}>|null, is_default: bool}>
     */
    public static function library(): array
    {
        return [
            [
                'name' => '5-point rating',
                'description' => 'The classic five-point appraisal scale.',
                'type' => 'numeric',
                'min' => 1, 'max' => 5, 'step' => 1, 'levels' => null,
                'is_default' => true,
            ],
            [
                'name' => '4-point (no midpoint)',
                'description' => 'Four points, so an evaluator cannot sit on the fence.',
                'type' => 'numeric',
                'min' => 1, 'max' => 4, 'step' => 1, 'levels' => null,
                'is_default' => false,
            ],
            [
                'name' => 'Goal attainment (%)',
                'description' => 'Achievement against a target, for quantified goals and quotas.',
                'type' => 'percentage',
                'min' => 0, 'max' => 100, 'step' => 1, 'levels' => null,
                'is_default' => false,
            ],
            [
                'name' => 'Competency level',
                'description' => 'Where the person sits on the capability ladder for this skill.',
                'type' => 'levels',
                'min' => 1, 'max' => 5, 'step' => 1,
                'levels' => [
                    ['value' => 1, 'label' => 'Learning', 'description' => 'Needs direction and close review.'],
                    ['value' => 2, 'label' => 'Developing', 'description' => 'Handles routine work with occasional support.'],
                    ['value' => 3, 'label' => 'Proficient', 'description' => 'Works independently to the standard of the role.'],
                    ['value' => 4, 'label' => 'Advanced', 'description' => 'Handles the hard cases and raises the standard.'],
                    ['value' => 5, 'label' => 'Expert', 'description' => 'Sets the standard and grows it in others.'],
                ],
                'is_default' => false,
            ],
            [
                'name' => 'Expectation rating',
                'description' => 'Plain-language levels for behaviour and values.',
                'type' => 'levels',
                'min' => 1, 'max' => 4, 'step' => 1,
                'levels' => [
                    ['value' => 1, 'label' => 'Below', 'description' => 'Falls short of what the role asks for.'],
                    ['value' => 2, 'label' => 'Meets', 'description' => 'Delivers what the role asks for.'],
                    ['value' => 3, 'label' => 'Exceeds', 'description' => 'Regularly goes past what the role asks for.'],
                    ['value' => 4, 'label' => 'Role model', 'description' => 'The reference point others are pointed at.'],
                ],
                'is_default' => false,
            ],
            [
                'name' => 'Met / not met',
                'description' => 'A binary check, for compliance and mandatory items.',
                'type' => 'levels',
                'min' => 0, 'max' => 1, 'step' => 1,
                'levels' => [
                    ['value' => 0, 'label' => 'Not met', 'description' => 'The requirement was not satisfied.'],
                    ['value' => 1, 'label' => 'Met', 'description' => 'The requirement was satisfied.'],
                ],
                'is_default' => false,
            ],
        ];
    }

    /**
     * A scale array with every field present and coherent, whatever came in.
     *
     * @param  array<string, mixed>  $scale
     * @return array{type: string, min: float, max: float, step: float, levels: list<array{value: float, label: string, description: string|null}>|null}
     */
    public static function normalize(array $scale): array
    {
        $type = in_array($scale['type'] ?? null, self::TYPES, true) ? (string) $scale['type'] : 'numeric';
        $levels = self::normalizeLevels($scale['levels'] ?? null);

        if ($type === 'percentage') {
            return ['type' => 'percentage', 'min' => 0.0, 'max' => 100.0, 'step' => (float) ($scale['step'] ?? 1), 'levels' => null];
        }

        if ($type === 'levels' && $levels !== null) {
            // A descriptive scale is bounded by its own levels, not by whatever
            // min/max happens to be stored alongside them.
            $values = array_column($levels, 'value');

            return ['type' => 'levels', 'min' => (float) min($values), 'max' => (float) max($values), 'step' => 1.0, 'levels' => $levels];
        }

        $min = (float) ($scale['min'] ?? 1);
        $max = (float) ($scale['max'] ?? 5);

        return [
            'type' => $type === 'levels' ? 'numeric' : $type,
            'min' => $min,
            'max' => $max <= $min ? $min + 1 : $max,
            'step' => max(0.01, (float) ($scale['step'] ?? 1)),
            'levels' => null,
        ];
    }

    /**
     * Ordered, de-duplicated levels — or null when there are none worth keeping.
     *
     * @return list<array{value: float, label: string, description: string|null}>|null
     */
    public static function normalizeLevels(mixed $levels): ?array
    {
        if (! is_array($levels)) {
            return null;
        }

        $normalized = [];

        foreach ($levels as $level) {
            if (! is_array($level) || ! isset($level['label']) || trim((string) $level['label']) === '') {
                continue;
            }

            $normalized[(string) (float) ($level['value'] ?? 0)] = [
                'value' => (float) ($level['value'] ?? 0),
                'label' => trim((string) $level['label']),
                'description' => isset($level['description']) && trim((string) $level['description']) !== ''
                    ? trim((string) $level['description'])
                    : null,
            ];
        }

        if ($normalized === []) {
            return null;
        }

        $normalized = array_values($normalized);
        usort($normalized, fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        return $normalized;
    }

    /**
     * Whether a raw score is a value this scale can actually take. A descriptive
     * scale accepts only its own level values; the others accept anything inside
     * their bounds.
     *
     * @param  array<string, mixed>  $scale
     */
    public static function accepts(float $value, array $scale): bool
    {
        $scale = self::normalize($scale);

        if ($scale['type'] === 'levels') {
            return in_array($value, array_column($scale['levels'] ?? [], 'value'), true);
        }

        return $value >= $scale['min'] && $value <= $scale['max'];
    }

    /**
     * A raw score's position on its scale as a 0–1 fraction, clamped. Null when
     * unscored; 0 for a degenerate scale that spans nothing.
     */
    public static function fraction(?float $value, ?float $min, ?float $max): ?float
    {
        if ($value === null) {
            return null;
        }

        $min ??= 0.0;
        $max ??= 100.0;
        $span = $max - $min;

        if ($span <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($value - $min) / $span));
    }

    /**
     * A score rendered in its own scale's language — "4", "82%", "Proficient".
     *
     * @param  array<string, mixed>  $scale
     */
    public static function format(?float $value, array $scale): string
    {
        if ($value === null) {
            return '—';
        }

        $scale = self::normalize($scale);

        if ($scale['type'] === 'percentage') {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'%';
        }

        if ($scale['type'] === 'levels') {
            foreach ($scale['levels'] ?? [] as $level) {
                if ($level['value'] === $value) {
                    return $level['label'];
                }
            }
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * A short descriptor of the instrument itself — "1–5", "0–100%", "5 levels".
     *
     * @param  array<string, mixed>  $scale
     */
    public static function descriptor(array $scale): string
    {
        $scale = self::normalize($scale);

        return match ($scale['type']) {
            'percentage' => '0–100%',
            'levels' => count($scale['levels'] ?? []).' levels',
            default => self::trim($scale['min']).'–'.self::trim($scale['max']),
        };
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

<?php

use App\Support\Performance\RatingScales;

/**
 * The reading of a rating scale — the one place that knows what a raw score
 * means on a numeric range, a percentage or a set of named levels. Scales reach
 * it from a model, from a frozen score-line snapshot and from HR's own JSON, so
 * everything here has to survive a partial array.
 */

// ── Normalising ──────────────────────────────────────────────────────────────

test('a levels scale is bounded by its own levels, not by stored min/max', function () {
    $scale = RatingScales::normalize([
        'type' => 'levels',
        'min' => 1,
        'max' => 99,
        'levels' => [
            ['value' => 0, 'label' => 'Not met'],
            ['value' => 1, 'label' => 'Met'],
        ],
    ]);

    expect($scale['min'])->toBe(0.0)->and($scale['max'])->toBe(1.0);
});

test('levels are ordered, de-duplicated and stripped of blanks', function () {
    $levels = RatingScales::normalizeLevels([
        ['value' => 3, 'label' => 'Exceeds'],
        ['value' => 1, 'label' => 'Below'],
        ['value' => 1, 'label' => 'Below again'],
        ['value' => 2, 'label' => '   '],
    ]);

    expect($levels)->toHaveCount(2)
        ->and(array_column($levels, 'value'))->toBe([1.0, 3.0])
        ->and($levels[0]['label'])->toBe('Below again');
});

test('a percentage scale is always 0–100 whatever bounds arrive', function () {
    $scale = RatingScales::normalize(['type' => 'percentage', 'min' => 40, 'max' => 60]);

    expect($scale['min'])->toBe(0.0)->and($scale['max'])->toBe(100.0);
});

test('a degenerate numeric range is widened rather than left inverted', function () {
    $scale = RatingScales::normalize(['type' => 'numeric', 'min' => 5, 'max' => 5]);

    expect($scale['max'])->toBeGreaterThan($scale['min']);
});

test('an unknown scale type falls back to numeric', function () {
    expect(RatingScales::normalize(['type' => 'stars'])['type'])->toBe('numeric');
});

// ── Accepting a rating ───────────────────────────────────────────────────────

test('a levels scale accepts only values it actually defines', function () {
    $scale = [
        'type' => 'levels',
        'levels' => [
            ['value' => 1, 'label' => 'Below'],
            ['value' => 3, 'label' => 'Exceeds'],
        ],
    ];

    expect(RatingScales::accepts(1, $scale))->toBeTrue()
        ->and(RatingScales::accepts(3, $scale))->toBeTrue()
        ->and(RatingScales::accepts(2, $scale))->toBeFalse();
});

test('a numeric scale accepts anything inside its bounds and nothing outside', function () {
    $scale = ['type' => 'numeric', 'min' => 1, 'max' => 4];

    expect(RatingScales::accepts(2.5, $scale))->toBeTrue()
        ->and(RatingScales::accepts(4, $scale))->toBeTrue()
        ->and(RatingScales::accepts(5, $scale))->toBeFalse()
        ->and(RatingScales::accepts(0, $scale))->toBeFalse();
});

// ── Reading a rating back ────────────────────────────────────────────────────

test('a score is rendered in its own scale’s language', function () {
    expect(RatingScales::format(82, ['type' => 'percentage']))->toBe('82%')
        ->and(RatingScales::format(4, ['type' => 'numeric', 'min' => 1, 'max' => 5]))->toBe('4')
        ->and(RatingScales::format(2, [
            'type' => 'levels',
            'levels' => [
                ['value' => 1, 'label' => 'Below'],
                ['value' => 2, 'label' => 'Proficient'],
            ],
        ]))->toBe('Proficient');
});

test('an unscored line reads as an em dash, not as zero', function () {
    expect(RatingScales::format(null, ['type' => 'numeric']))->toBe('—');
});

test('the descriptor names the instrument in three words', function () {
    expect(RatingScales::descriptor(['type' => 'numeric', 'min' => 1, 'max' => 5]))->toBe('1–5')
        ->and(RatingScales::descriptor(['type' => 'percentage']))->toBe('0–100%')
        ->and(RatingScales::descriptor([
            'type' => 'levels',
            'levels' => [
                ['value' => 1, 'label' => 'A'],
                ['value' => 2, 'label' => 'B'],
                ['value' => 3, 'label' => 'C'],
            ],
        ]))->toBe('3 levels');
});

test('the starter library is coherent under normalisation', function () {
    foreach (RatingScales::library() as $scale) {
        $normalized = RatingScales::normalize($scale);

        expect($normalized['max'])->toBeGreaterThan($normalized['min'])
            ->and($normalized['type'])->toBe($scale['type']);
    }
});

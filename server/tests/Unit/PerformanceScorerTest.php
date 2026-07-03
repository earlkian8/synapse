<?php

use App\Support\Performance\PerformanceScorer;

/**
 * Pure, DB-free coverage of the scale-agnostic overall. Each line is normalised
 * to a 0–1 fraction of its own scale, weighted-averaged, then projected onto the
 * canonical 1–5 overall.
 */
beforeEach(function () {
    $this->scorer = new PerformanceScorer;
});

test('a legacy 1–5 points evaluation scores as the weighted average of ratings', function () {
    // 4@60% + 2@40% = 3.2 on the 1–5 scale (backward compatible).
    $overall = $this->scorer->overall([
        ['score' => 4, 'weight' => 60, 'scale_min' => 1, 'scale_max' => 5],
        ['score' => 2, 'weight' => 40, 'scale_min' => 1, 'scale_max' => 5],
    ]);

    expect($overall)->toBe(3.2);
});

test('lines with no scale bounds default to the legacy 1–5 scale', function () {
    expect($this->scorer->overall([['score' => 5, 'weight' => 100]]))->toBe(5.0)
        ->and($this->scorer->overall([['score' => 1, 'weight' => 100]]))->toBe(1.0);
});

test('mixed scales are normalised before they are combined', function () {
    // 80% (fraction 0.8) and 5/5 (fraction 1.0), evenly weighted → 0.9 → 4.6.
    $overall = $this->scorer->overall([
        ['score' => 80, 'weight' => 50, 'scale_min' => 0, 'scale_max' => 100],
        ['score' => 5, 'weight' => 50, 'scale_min' => 1, 'scale_max' => 5],
    ]);

    expect($overall)->toBe(4.6);
});

test('a 1–10 points scale maps its midpoint to the 1–5 midpoint', function () {
    // 5.5 on a 1–10 scale is the exact midpoint (fraction 0.5) → 3.0.
    expect($this->scorer->overall([
        ['score' => 5.5, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 10],
    ]))->toBe(3.0);
});

test('a descriptive top level scores at the top of the overall scale', function () {
    // Level value 3 on a 1–3 descriptive scale → fraction 1.0 → 5.0.
    expect($this->scorer->overall([
        ['score' => 3, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 3],
    ]))->toBe(5.0);
});

test('only scored lines contribute; an all-unscored card is null', function () {
    expect($this->scorer->overall([
        ['score' => null, 'weight' => 50, 'scale_min' => 1, 'scale_max' => 5],
        ['score' => null, 'weight' => 50, 'scale_min' => 0, 'scale_max' => 100],
    ]))->toBeNull();
});

test('zero-weight lines fall back to an unweighted mean of fractions', function () {
    // Fractions 1.0 and 0.0, no weights → mean 0.5 → 3.0.
    expect($this->scorer->overall([
        ['score' => 5, 'weight' => 0, 'scale_min' => 1, 'scale_max' => 5],
        ['score' => 0, 'weight' => 0, 'scale_min' => 0, 'scale_max' => 100],
    ]))->toBe(3.0);
});

test('a raw score outside its scale is clamped, not extrapolated', function () {
    expect($this->scorer->overall([
        ['score' => 120, 'weight' => 100, 'scale_min' => 0, 'scale_max' => 100],
    ]))->toBe(5.0);
});

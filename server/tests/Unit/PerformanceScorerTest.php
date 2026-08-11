<?php

use App\Support\Performance\PerformanceScorer;
use App\Support\Performance\RatingModel;

/**
 * Pure, DB-free coverage of the two-level scoring contract: each line is read on
 * its own scale and weighted within its section; the sections are then weighted
 * against each other; the result is attainment on 0–100, projected onto the
 * canonical 1–5 overall and read into the tenant's rating model.
 */
beforeEach(function () {
    $this->scorer = new PerformanceScorer;
});

// ── The 1–5 projection (the contract everything outside Performance reads) ────

test('a flat 1–5 card scores as the weighted average of its ratings', function () {
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
    expect($this->scorer->overall([
        ['score' => 5.5, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 10],
    ]))->toBe(3.0);
});

test('a descriptive top level scores at the top of the overall scale', function () {
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

// ── Attainment and two-level weighting ───────────────────────────────────────

test('attainment is the position on 0–100, not the 1–5 rating', function () {
    $result = $this->scorer->score([
        ['score' => 4, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 5],
    ]);

    // 4 of 5 is three quarters of the way up a 1–5 scale.
    expect($result->percent)->toBe(75.0)
        ->and($result->normalized)->toBe(4.0);
});

test('sections are weighted against each other, lines within their section', function () {
    // Goals 60% (100% attained) + Values 40% (0% attained) → 60.
    $result = $this->scorer->score([
        ['score' => 5, 'weight' => 50, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'goals', 'section_weight' => 60],
        ['score' => 5, 'weight' => 50, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'goals', 'section_weight' => 60],
        ['score' => 1, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'values', 'section_weight' => 40],
    ]);

    expect($result->percent)->toBe(60.0)
        ->and($result->sections)->toHaveCount(2);
});

test('a section carrying no weight of its own falls back to its lines’ weight', function () {
    // With no declared section weights this must reduce to the flat weighted
    // average across every line: (0.75*60 + 0.25*40) / 100 = 0.55 → 55%.
    $result = $this->scorer->score([
        ['score' => 4, 'weight' => 60, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'a'],
        ['score' => 2, 'weight' => 40, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'b'],
    ]);

    expect($result->percent)->toBe(55.0);
});

test('an unscored section does not drag the running result down', function () {
    // Goals is fully rated; Values has nothing yet, so it is left out entirely.
    $result = $this->scorer->score([
        ['score' => 5, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'goals', 'section_weight' => 60],
        ['score' => null, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'values', 'section_weight' => 40],
    ]);

    expect($result->percent)->toBe(100.0)
        ->and($result->isComplete())->toBeFalse()
        ->and($result->scored)->toBe(1)
        ->and($result->total)->toBe(2);
});

test('a section reports its own attainment and how much of it is rated', function () {
    $result = $this->scorer->score([
        ['score' => 5, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'goals', 'section_name' => 'Goals', 'section_weight' => 100],
        ['score' => null, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 5, 'section_key' => 'goals', 'section_name' => 'Goals', 'section_weight' => 100],
    ]);

    expect($result->sections[0])->toMatchArray([
        'key' => 'goals',
        'name' => 'Goals',
        'weight' => 100.0,
        'percent' => 100.0,
        'scored' => 1,
        'total' => 2,
    ]);
});

// ── The rating model ─────────────────────────────────────────────────────────

test('the result is read into the tenant’s own rating model', function () {
    $bands = [
        ['key' => 'a', 'label' => 'A', 'min_percent' => 80],
        ['key' => 'b', 'label' => 'B', 'min_percent' => 60],
        ['key' => 'c', 'label' => 'C', 'min_percent' => 0],
    ];

    $result = $this->scorer->score(
        [['score' => 4, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 5]],
        $bands,
    );

    // 75% reaches B but not A.
    expect($result->band['label'])->toBe('B');
});

test('bands are read top-down whatever order they arrive in', function () {
    $bands = RatingModel::normalize([
        ['label' => 'Meets', 'min_percent' => 55],
        ['label' => 'Outstanding', 'min_percent' => 90],
        ['label' => 'Below', 'min_percent' => 0],
    ]);

    expect(array_column($bands, 'label'))->toBe(['Outstanding', 'Meets', 'Below'])
        ->and(RatingModel::bandFor(95, $bands)['label'])->toBe('Outstanding')
        ->and(RatingModel::bandFor(60, $bands)['label'])->toBe('Meets')
        ->and(RatingModel::bandFor(10, $bands)['label'])->toBe('Below');
});

test('a band with no tone of its own reads as neutral, never as a colour', function () {
    $bands = RatingModel::normalize([
        ['label' => 'Top', 'min_percent' => 50, 'tone' => 'chartreuse'],
        ['label' => 'Rest', 'min_percent' => 0, 'tone' => 'critical'],
    ]);

    expect($bands[0]['tone'])->toBe('neutral')
        ->and($bands[1]['tone'])->toBe('critical');
});

test('an unrated card has no band at all', function () {
    $result = $this->scorer->score(
        [['score' => null, 'weight' => 100, 'scale_min' => 1, 'scale_max' => 5]],
        RatingModel::defaultBands(),
    );

    expect($result->percent)->toBeNull()
        ->and($result->band)->toBeNull();
});

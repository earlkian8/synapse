<?php

use App\Support\LeaveCalculator;
use Illuminate\Support\Carbon;

// These are DB-free: pure date arithmetic.

test('working days exclude weekends', function () {
    // Mon 2026-06-08 → Fri 2026-06-12 is five working days.
    expect(LeaveCalculator::workingDays(
        Carbon::parse('2026-06-08'),
        Carbon::parse('2026-06-12'),
    ))->toBe(5);
});

test('working days skip the weekend in a span', function () {
    // Fri 2026-06-12 → Mon 2026-06-15 spans a weekend: only Fri + Mon count.
    expect(LeaveCalculator::workingDays(
        Carbon::parse('2026-06-12'),
        Carbon::parse('2026-06-15'),
    ))->toBe(2);
});

test('a single weekday is one working day', function () {
    expect(LeaveCalculator::workingDays(
        Carbon::parse('2026-06-10'),
        Carbon::parse('2026-06-10'),
    ))->toBe(1);
});

test('a reversed range is zero', function () {
    expect(LeaveCalculator::workingDays(
        Carbon::parse('2026-06-12'),
        Carbon::parse('2026-06-08'),
    ))->toBe(0);
});

test('a half day on a weekday charges half', function () {
    $day = Carbon::parse('2026-06-10'); // Wednesday

    expect(LeaveCalculator::chargeableDays($day, $day, true))->toBe(0.5);
});

test('a half day on a weekend charges nothing', function () {
    $day = Carbon::parse('2026-06-13'); // Saturday

    expect(LeaveCalculator::chargeableDays($day, $day, false))->toBe(0.0)
        ->and(LeaveCalculator::chargeableDays($day, $day, true))->toBe(0.0);
});

test('a multi-day request ignores the half-day flag', function () {
    // Half day only applies to a single day; a range charges whole working days.
    expect(LeaveCalculator::chargeableDays(
        Carbon::parse('2026-06-08'),
        Carbon::parse('2026-06-09'),
        true,
    ))->toBe(2.0);
});

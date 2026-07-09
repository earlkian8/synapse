<?php

use App\Support\Awards\AwardNominator;

/**
 * Pure, DB-free coverage of the nomination profile classifier, the fit bands
 * and the weight tables the board normalises over.
 */
test('award types are classified into the focus profile their name implies', function (string $name, ?string $description, string $expected) {
    expect(AwardNominator::profileFor($name, $description))->toBe($expected);
})->with([
    'perfect attendance' => ['Perfect Attendance', 'No absences or tardiness for the period.', 'attendance'],
    'punctuality star' => ['Punctuality Star', null, 'attendance'],
    'years of service' => ['Years of Service', 'A milestone work anniversary with the company.', 'tenure'],
    'loyalty award' => ['Loyalty Award', null, 'tenure'],
    'best learner' => ['Best Learner', 'Most trainings completed this year.', 'growth'],
    'certification champ' => ['Certification Champion', null, 'growth'],
    'employee of the month' => ['Employee of the Month', 'Outstanding all-round contribution for the month.', 'performance'],
    'top performer' => ['Top Performer', null, 'performance'],
    'spot award' => ['Spot Award', 'On-the-spot recognition for going above and beyond.', 'allround'],
    'innovation award' => ['Innovation Award', 'A process improvement or idea that made an impact.', 'allround'],
    'teamwork trophy' => ['Teamwork Trophy', null, 'allround'],
]);

test('the description alone can steer the profile', function () {
    expect(AwardNominator::profileFor('Golden Badge', 'Given for perfect attendance and punctuality.'))
        ->toBe('attendance');
});

test('every profile weight table sums to 100 before normalisation', function () {
    foreach (AwardNominator::PROFILES as $weights) {
        expect(array_sum($weights))->toBe(100);
    }
});

test('every profile has a label and a hint for the board header', function () {
    foreach (array_keys(AwardNominator::PROFILES) as $profile) {
        expect(AwardNominator::PROFILE_LABELS)->toHaveKey($profile)
            ->and(AwardNominator::PROFILE_LABELS[$profile]['label'])->not->toBe('')
            ->and(AwardNominator::PROFILE_LABELS[$profile]['hint'])->not->toBe('');
    }
});

test('fit scores band from weak to strong at the shared thresholds', function () {
    expect(AwardNominator::band(90))->toBe('strong')
        ->and(AwardNominator::band(75))->toBe('strong')
        ->and(AwardNominator::band(74))->toBe('promising')
        ->and(AwardNominator::band(55))->toBe('promising')
        ->and(AwardNominator::band(54))->toBe('fair')
        ->and(AwardNominator::band(35))->toBe('fair')
        ->and(AwardNominator::band(34))->toBe('weak')
        ->and(AwardNominator::band(0))->toBe('weak');
});

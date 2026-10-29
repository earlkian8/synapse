<?php

use App\Support\Recruitment\ResumeExperience;
use Carbon\CarbonImmutable;

/**
 * Years of experience read from a résumé (ADR 0097), on a fixed "today".
 */
function atsYears(string $text): ?array
{
    return (new ResumeExperience)->estimate($text, CarbonImmutable::parse('2026-10-29'));
}

test('job date ranges are added up, the education section left out', function () {
    $resume = <<<'TXT'
        WORK EXPERIENCE
        Software Engineer
        ABC Corp | Jan 2019 – Present
        Junior Developer, XYZ Inc. 2016 - 2018
        EDUCATION
        BS Computer Science, UP Diliman 2012 – 2016
        TXT;

    expect(atsYears($resume))->toBe(['years' => 10.0, 'basis' => 'dates']);
});

test('overlapping jobs are counted once', function () {
    $resume = "Online tutor, 03/2019 to 06/2021\nTeacher I, Manila Science High School, June 2015 - March 2020";

    // June 2015 → June 2021, the tutoring inside it.
    expect(atsYears($resume)['years'])->toBe(6.0);
});

test('a line about schooling or training is not work, even outside a heading', function () {
    expect(atsYears("Bachelor of Science in Accountancy 2014 - 2018\nOJT at ACME, Jan 2018 - Mar 2018"))->toBeNull();
});

test('without dates, the candidate’s own statement is taken', function () {
    expect(atsYears('Accountant with 7+ years of experience in audit.'))->toBe(['years' => 7.0, 'basis' => 'statement'])
        ->and(atsYears('Over 3.5 yrs working experience'))->toBe(['years' => 3.5, 'basis' => 'statement']);
});

test('nothing to go on is no estimate', function () {
    expect(atsYears('Phone 0917-123-4567, born 1998'))->toBeNull()
        ->and(atsYears(''))->toBeNull();
});

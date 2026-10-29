<?php

namespace App\Support\Recruitment;

use Carbon\CarbonImmutable;

/**
 * Years of work experience read from a résumé's text (ADR 0097), for a
 * candidate who didn't say — so leaving the field blank no longer scores as
 * having none.
 *
 * It adds up the date ranges of the jobs listed ("Jan 2019 – Present",
 * "2016 - 2020", "03/2018 to 06/2021"), counting overlapping jobs once and
 * leaving out education, training and the like. With no ranges it takes the
 * candidate's own claim ("5+ years of experience"). It is an estimate, shown
 * as one, and never used when the candidate gave a figure.
 */
class ResumeExperience
{
    /**
     * Words that mark a line as schooling or training rather than a job. A
     * school's name alone doesn't — teachers work at schools; an education
     * section is recognised by its heading instead.
     */
    private const SCHOOL = '/\b(bachelor\'?s?|master\'?s|masters|degree|diploma|bs[a-z]{0,3}|ba|ms|mba|ph\.?d|graduated?|graduating|cum laude|magna|summa|elementary|primary|secondary|tertiary|k ?- ?12|major in|thesis|scholar|dean\'?s list|ojt|on[- ]the[- ]job training|intern(ship)?|seminar|webinar|workshop|certificat(e|ion)|training)\b/i';

    /** Headings that open a section of school, training or other non-work. */
    private const OTHER_HEADING = '/^\W*(education(al)?( background| attainment)?|academic( background| history)?|trainings?( attended)?|seminars?( attended)?|certifications?|licen[sc]es?( and certifications?)?|awards?|achievements|affiliations?|organi[sz]ations?|references?|character references?|personal (information|data|details)|skills|interests|hobbies)\W*$/i';

    /** Headings that open the work history. */
    private const WORK_HEADING = '/^\W*((work|professional|employment|career|relevant|job)( experience| history| background)?|experience|work history|employment record)\W*$/i';

    private const MONTH = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';

    /**
     * @return array{years: float, basis: string}|null `basis` is `dates` or `statement`
     */
    public function estimate(?string $text, ?CarbonImmutable $today = null): ?array
    {
        if (blank($text)) {
            return null;
        }

        $today ??= CarbonImmutable::now();
        $months = $this->fromDates($text, $today);

        if ($months !== null && $months >= 3) {
            return ['years' => $this->round($months / 12), 'basis' => 'dates'];
        }

        $claimed = $this->fromStatement($text);

        return $claimed === null ? null : ['years' => $this->round($claimed), 'basis' => 'statement'];
    }

    /**
     * The months covered by work date ranges, overlaps counted once.
     */
    private function fromDates(string $text, CarbonImmutable $today): ?int
    {
        $month = self::MONTH;
        $point = "(?:{$month}\\.?,?\\s*(?:\\d{1,2},?\\s*)?((?:19|20)\\d{2})|(\\d{1,2})\\s*[\\/.-]\\s*((?:19|20)\\d{2})|((?:19|20)\\d{2}))";
        $end = "(?:{$point}|(present|current(?:ly)?|now|to date|today|ongoing|up to present))";
        $pattern = "/{$point}\\s*(?:-|–|—|to|until|till|~)\\s*{$end}/i";

        $now = $today->year * 12 + $today->month - 1;
        $intervals = [];
        $section = 'work';

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);

            if (strlen($trimmed) <= 40 && preg_match(self::WORK_HEADING, $trimmed) === 1) {
                $section = 'work';

                continue;
            }

            if (strlen($trimmed) <= 40 && preg_match(self::OTHER_HEADING, $trimmed) === 1) {
                $section = 'other';

                continue;
            }

            if ($section !== 'work' || preg_match(self::SCHOOL, $trimmed) === 1) {
                continue;
            }

            if (preg_match_all($pattern, $trimmed, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $start = $this->monthIndex($match[1] ?? '', $match[2] ?? '', $match[3] ?? '', $match[4] ?? '', $match[5] ?? '', false);
                $finish = filled($match[11] ?? null)
                    ? $now
                    : $this->monthIndex($match[6] ?? '', $match[7] ?? '', $match[8] ?? '', $match[9] ?? '', $match[10] ?? '', true);

                if ($start === null || $finish === null || $finish < $start || $start > $now) {
                    continue;
                }

                $finish = min($finish, $now);

                // A range of more than 45 years is a misreading, not a career.
                if ($finish - $start > 45 * 12) {
                    continue;
                }

                $intervals[] = [$start, max($finish, $start + 1)];
            }
        }

        if ($intervals === []) {
            return null;
        }

        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $total = 0;
        [$from, $to] = $intervals[0];

        foreach (array_slice($intervals, 1) as [$start, $finish]) {
            if ($start <= $to) {
                $to = max($to, $finish);

                continue;
            }

            $total += $to - $from;
            [$from, $to] = [$start, $finish];
        }

        return $total + ($to - $from);
    }

    /**
     * A point in time as a month count. A year alone opens a range in January
     * and closes it in January too, so "2018 - 2020" is two years.
     */
    private function monthIndex(string $monthName, string $monthYear, string $numericMonth, string $numericYear, string $yearOnly, bool $isEnd): ?int
    {
        if ($monthName !== '' && $monthYear !== '') {
            return (int) $monthYear * 12 + $this->monthNumber($monthName) - 1;
        }

        if ($numericMonth !== '' && $numericYear !== '') {
            $month = (int) $numericMonth;

            return $month >= 1 && $month <= 12 ? (int) $numericYear * 12 + $month - 1 : null;
        }

        if ($yearOnly !== '') {
            return (int) $yearOnly * 12;
        }

        return null;
    }

    private function monthNumber(string $name): int
    {
        $index = array_search(strtolower(substr($name, 0, 3)), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);

        return $index === false ? 1 : $index + 1;
    }

    /**
     * The largest number of years the candidate says they have ("over 5 years
     * of experience", "7+ yrs working experience").
     */
    private function fromStatement(string $text): ?float
    {
        $pattern = '/(\d{1,2}(?:\.\d)?)\s*\+?\s*(?:years?|yrs?)\.?\s+(?:of\s+)?(?:solid\s+|total\s+|professional\s+|work(?:ing)?\s+|relevant\s+|industry\s+|hands[- ]on\s+)*(?:work\s+)?experience/i';

        if (preg_match_all($pattern, $text, $matches) < 1) {
            return null;
        }

        $years = max(array_map('floatval', $matches[1]));

        return $years > 0 && $years <= 50 ? $years : null;
    }

    /** To the nearest half year, as the posting's minimum is written. */
    private function round(float $years): float
    {
        return min(50.0, round($years * 2) / 2);
    }
}

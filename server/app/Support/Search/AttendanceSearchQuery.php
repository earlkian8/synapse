<?php

namespace App\Support\Search;

use Carbon\CarbonImmutable;

/**
 * What a search says about attendance (ADR 0081), read out of its words: a day
 * ("today", "yesterday", "2026-10-03", "10/3", "oct 3", "3 october 2026"), a
 * month ("october", "oct 2026"), the kind of day ("late", "absent", "overtime",
 * "on leave"…), and whatever is left — the person's name or number.
 *
 * Attendance answers only when one of those cues is there, or the word
 * "attendance" (or "dtr"): a search for a name alone means the person, and
 * should not bury them under a month of their days. "Leave", "holiday",
 * "present" and "rest" also name screens and leave types, so alone they are
 * no cue: "leave" means the Leave screen, "maria leave" her days on leave.
 */
final readonly class AttendanceSearchQuery
{
    /** Words that only say "attendance". */
    private const CUES = ['attendance', 'dtr', 'timesheet', 'time', 'record', 'records', 'punch', 'punches', 'days', 'day'];

    /** The kind of day each word asks for. */
    public const KINDS = [
        'late' => 'late', 'tardy' => 'late', 'tardiness' => 'late',
        'undertime' => 'undertime',
        'overtime' => 'overtime', 'ot' => 'overtime',
        'absent' => 'absent', 'absence' => 'absent', 'absences' => 'absent', 'awol' => 'absent',
        'leave' => 'on_leave',
        'holiday' => 'holiday', 'holidays' => 'holiday',
        'incomplete' => 'incomplete', 'missing' => 'incomplete',
        'present' => 'present',
        'halfday' => 'half_day', 'half-day' => 'half_day',
        'rest' => 'day_off', 'dayoff' => 'day_off',
    ];

    /** Kinds that cue attendance only beside a person or a day. */
    private const WEAK_KINDS = ['leave', 'holiday', 'holidays', 'present', 'rest'];

    /** Words around a kind that add nothing ("on leave", "half day"). */
    private const FILLERS = ['on', 'half', 'off', 'of', 'in', 'at', 'for'];

    private const MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
    ];

    /**
     * @param  list<string>  $kinds  Kinds of day, any of which matches.
     * @param  list<string>  $words  What is left: the person.
     */
    public function __construct(
        public ?string $from,
        public ?string $to,
        public array $kinds,
        public array $words,
        public bool $cued,
    ) {}

    /**
     * @param  list<string>  $words  Lowercased, as {@see GlobalSearch::words()} gives them.
     */
    public static function parse(array $words, string $today): self
    {
        $now = CarbonImmutable::parse($today);
        $from = null;
        $to = null;
        $kinds = [];
        $left = [];
        $cued = false;
        $weak = false;
        $count = count($words);

        for ($i = 0; $i < $count; $i++) {
            $word = trim($words[$i], ',.');
            $next = $words[$i + 1] ?? null;
            $after = $words[$i + 2] ?? null;

            if ($word === 'today' || $word === 'yesterday') {
                $from = $to = ($word === 'today' ? $now : $now->subDay())->toDateString();
                $cued = true;

                continue;
            }

            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $word, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $from = $to = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                $cued = true;

                continue;
            }

            if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{2}|\d{4}))?$#', $word, $m)) {
                $year = isset($m[3]) ? (strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3]) : $now->year;

                if (checkdate((int) $m[1], (int) $m[2], $year)) {
                    $from = $to = sprintf('%04d-%02d-%02d', $year, $m[1], $m[2]);
                    $cued = true;

                    continue;
                }
            }

            // "oct", "oct 3", "oct 3 2026", "october 2026"
            if (isset(self::MONTHS[$word])) {
                $month = self::MONTHS[$word];
                $day = $next !== null && ctype_digit($next) && (int) $next >= 1 && (int) $next <= 31 ? (int) $next : null;
                $yearWord = $day !== null ? $after : $next;
                $year = $yearWord !== null && preg_match('/^\d{4}$/', $yearWord) ? (int) $yearWord : $now->year;

                if ($day !== null && checkdate($month, $day, $year)) {
                    $from = $to = sprintf('%04d-%02d-%02d', $year, $month, $day);
                } else {
                    $first = CarbonImmutable::create($year, $month, 1);
                    $from = $first->toDateString();
                    $to = $first->endOfMonth()->toDateString();
                }

                $i += ($day !== null ? 1 : 0) + ($yearWord !== null && preg_match('/^\d{4}$/', $yearWord) ? 1 : 0);
                $cued = true;

                continue;
            }

            // "3 oct", "3 october 2026"
            if (ctype_digit($word) && $next !== null && isset(self::MONTHS[$next])) {
                $month = self::MONTHS[$next];
                $year = $after !== null && preg_match('/^\d{4}$/', $after) ? (int) $after : $now->year;

                if (checkdate($month, (int) $word, $year)) {
                    $from = $to = sprintf('%04d-%02d-%02d', $year, $month, (int) $word);
                    $i += 1 + ($after !== null && preg_match('/^\d{4}$/', $after) ? 1 : 0);
                    $cued = true;

                    continue;
                }
            }

            if (isset(self::KINDS[$word])) {
                $kinds[] = self::KINDS[$word];

                if (in_array($word, self::WEAK_KINDS, true)) {
                    $weak = true;
                } else {
                    $cued = true;
                }

                continue;
            }

            if (in_array($word, self::CUES, true)) {
                $cued = $cued || in_array($word, ['attendance', 'dtr', 'timesheet', 'punch', 'punches'], true);

                continue;
            }

            if (in_array($word, self::FILLERS, true)) {
                continue;
            }

            $left[] = $word;
        }

        return new self($from, $to, array_values(array_unique($kinds)), $left, $cued || ($weak && $left !== []));
    }
}

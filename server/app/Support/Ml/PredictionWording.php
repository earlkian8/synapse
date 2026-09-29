<?php

namespace App\Support\Ml;

use App\Models\PerformanceForecast;
use App\Support\Employees\EmployeeDisclosure;

/**
 * The predictive surfaces' words, as the screens say them — for everything that
 * describes a score outside its own page (the assistant; ADR 0058).
 *
 * The screens keep these in their TypeScript constants; this is the server-side
 * copy, so "At watch", "Below target" and "Appraisal still in draft" read the
 * same in chat as on the page. One input never leaves here: the attrition model
 * takes pay, and pay is withheld from the assistant for everybody
 * ({@see EmployeeDisclosure::WITHHELD}).
 */
final class PredictionWording
{
    /** Attrition risk tiers, most urgent first. */
    public const RISK_TIERS = ['high' => 'High risk', 'medium' => 'At watch', 'low' => 'Stable'];

    public const RISK_MEANING = [
        'high' => 'likely to leave — prioritise retention',
        'medium' => 'some risk — worth a check-in',
        'low' => 'settled — no immediate concern',
    ];

    /** Promotion readiness tiers, highest first. */
    public const READINESS_TIERS = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    public const READINESS_MEANING = [
        'high' => 'at least twice as likely as average to be promoted',
        'medium' => 'at or above average odds of promotion',
        'low' => 'below average odds of promotion',
    ];

    /** Performance forecast bands, highest first. */
    public const BANDS = ['exceeds' => 'Exceeds', 'on_track' => 'On track', 'below' => 'Below target'];

    public const BAND_MEANING = [
        'exceeds' => 'projected to exceed expectations',
        'on_track' => 'projected to meet expectations',
        'below' => 'projected below target — may need support',
    ];

    /** Why a model declined somebody, and what would include them. */
    public const UNASSESSED = [
        AppraisalHistory::NO_APPRAISAL => ['No appraisal on record', 'complete an appraisal for them'],
        AppraisalHistory::APPRAISAL_IN_PROGRESS => ['Appraisal still in draft', 'submit their appraisal'],
        AppraisalHistory::NONE_BEFORE_PERIOD => ['Only appraised in the period being forecast', 'included once a later period is forecast'],
    ];

    /** Attrition inputs never named or valued outside the page: pay. */
    public const WITHHELD_INPUTS = ['monthly_salary'];

    /** The attrition inputs, in the page's order, with their labels. */
    private const ATTRITION_INPUTS = [
        'employment_type' => 'Employment type',
        'tenure_years' => 'Tenure',
        'years_since_promotion' => 'Since last promotion',
        'ever_promoted' => 'Promoted here before',
        'overtime_hours_90d' => 'Overtime, last 90 days',
        'absences_90d' => 'Absences, last 90 days',
        'lates_90d' => 'Late arrivals, last 90 days',
    ];

    private const EMPLOYMENT_TYPES = ['regular' => 'Regular', 'probationary' => 'Probationary', 'part_time' => 'Part-time', 'contractual' => 'Contractual'];

    /**
     * An attrition input the assistant may state, as "Tenure: 1.5 yrs" — or null
     * for pay and for anything it does not know.
     */
    public static function attritionInput(string $key, mixed $value): ?string
    {
        if (in_array($key, self::WITHHELD_INPUTS, true) || ! isset(self::ATTRITION_INPUTS[$key]) || ! is_scalar($value)) {
            return null;
        }

        $shown = match ($key) {
            'employment_type' => self::EMPLOYMENT_TYPES[(string) $value] ?? (string) $value,
            'tenure_years', 'years_since_promotion' => self::years((float) $value),
            'ever_promoted' => (float) $value > 0 ? 'Yes' : 'Never',
            'overtime_hours_90d' => round((float) $value).' h',
            'absences_90d' => self::count((float) $value, 'day', 'days'),
            'lates_90d' => self::count((float) $value, 'time', 'times'),
        };

        return self::ATTRITION_INPUTS[$key].': '.$shown;
    }

    /**
     * The label of an attrition input that moved a score — or null for pay.
     */
    public static function attritionFactor(string $key): ?string
    {
        return in_array($key, self::WITHHELD_INPUTS, true) ? null : (self::ATTRITION_INPUTS[$key] ?? null);
    }

    /**
     * A forecast in one line: "74% (likely 66–81) · On track, 71% chance".
     */
    public static function forecast(PerformanceForecast $forecast): string
    {
        $range = $forecast->predicted_low !== null && $forecast->predicted_high !== null
            ? ' (likely '.self::number((float) $forecast->predicted_low).'–'.self::number((float) $forecast->predicted_high).')'
            : '';

        return self::number((float) $forecast->predicted_rating).'%'.$range.' · '
            .(self::BANDS[$forecast->band] ?? (string) $forecast->band).', '.self::percent((float) $forecast->confidence).' chance';
    }

    /**
     * A 0–1 share as a whole percentage: "71%".
     */
    public static function percent(float $share): string
    {
        return round($share * 100).'%';
    }

    /**
     * A 0–100 risk or readiness score as the pages show it: a whole number, "54".
     */
    public static function score(float $score): string
    {
        return (string) (int) round($score);
    }

    /**
     * A rating or score with at most one decimal: "74", "73.5".
     */
    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /**
     * "8 mos" under a year, "1.5 yrs" from one.
     */
    private static function years(float $years): string
    {
        if ($years < 1) {
            $months = max(0, (int) round($years * 12));

            return $months.' '.($months === 1 ? 'mo' : 'mos');
        }

        return number_format($years, 1).' yrs';
    }

    private static function count(float $value, string $one, string $many): string
    {
        $n = (int) round($value);

        return $n.' '.($n === 1 ? $one : $many);
    }
}

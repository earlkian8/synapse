<?php

namespace App\Support\Payroll;

use App\Models\DeductionType;

/**
 * Pure payroll arithmetic — basic / overtime pay from a monthly salary, and the
 * amount of a deduction from its {@see DeductionType} `computation`
 * config (a flat rate with optional floor/cap, or a progressive bracket).
 *
 * The model is deliberately simple and deterministic — a monthly salary spread
 * over a standard working month — so a run is reproducible. It approximates
 * Philippine statutory rules for a believable demo; it is **not** a
 * legally-exact payroll engine.
 */
class PayrollCalculator
{
    /** Standard working days in a month (pay basis for the daily rate). */
    public const WORKING_DAYS_PER_MONTH = 22;

    /** Standard working hours in a day (basis for the hourly rate). */
    public const HOURS_PER_DAY = 8;

    /** Overtime premium (125% of the hourly rate). */
    public const OVERTIME_MULTIPLIER = 1.25;

    /**
     * The daily rate implied by a monthly salary.
     */
    public static function dailyRate(float $monthlySalary): float
    {
        return $monthlySalary / self::WORKING_DAYS_PER_MONTH;
    }

    /**
     * Basic pay for the days actually worked in the period.
     */
    public static function basicPay(float $monthlySalary, float $daysWorked): float
    {
        return round(self::dailyRate($monthlySalary) * $daysWorked, 2);
    }

    /**
     * Overtime pay for the hours logged, at the overtime premium.
     */
    public static function overtimePay(float $monthlySalary, float $overtimeHours): float
    {
        $hourly = self::dailyRate($monthlySalary) / self::HOURS_PER_DAY;

        return round($hourly * self::OVERTIME_MULTIPLIER * $overtimeHours, 2);
    }

    /**
     * The amount of one deduction, interpreting its computation config:
     *
     *  - `{"type":"rate","rate":r,"base":"salary|taxable","min":m,"max":cap}`
     *  - `{"type":"bracket","brackets":[{"over":x,"base":b,"rate":r}, …]}` (on the
     *    taxable base; brackets ascending by `over`).
     *
     * @param  array<string, mixed>  $computation
     */
    public static function deductionAmount(array $computation, float $monthlySalary, float $taxableBase): float
    {
        $type = $computation['type'] ?? 'rate';

        if ($type === 'bracket') {
            return self::bracketAmount($taxableBase, $computation['brackets'] ?? []);
        }

        $base = ($computation['base'] ?? 'salary') === 'taxable' ? $taxableBase : $monthlySalary;
        $amount = $base * (float) ($computation['rate'] ?? 0);

        if (isset($computation['min'])) {
            $amount = max($amount, (float) $computation['min']);
        }

        if (isset($computation['max'])) {
            $amount = min($amount, (float) $computation['max']);
        }

        return round(max(0, $amount), 2);
    }

    /**
     * Progressive bracket tax: the highest bracket whose `over` threshold the
     * taxable amount exceeds sets `base + (taxable − over) × rate`.
     *
     * @param  list<array<string, mixed>>  $brackets
     */
    private static function bracketAmount(float $taxable, array $brackets): float
    {
        $amount = 0.0;

        foreach ($brackets as $bracket) {
            $over = (float) ($bracket['over'] ?? 0);

            if ($taxable > $over) {
                $amount = (float) ($bracket['base'] ?? 0) + ($taxable - $over) * (float) ($bracket['rate'] ?? 0);
            }
        }

        return round(max(0, $amount), 2);
    }
}

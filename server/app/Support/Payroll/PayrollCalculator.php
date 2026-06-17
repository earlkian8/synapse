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
     * Reconcile a payslip's totals from its lines — the single source of truth
     * for the totals contract, reused by the processor and by manual edits:
     *
     *   total_earnings   = Σ earning-line amounts (the allowances)
     *   gross_pay        = basic_pay + overtime_pay + total_earnings
     *   total_deductions = Σ deduction-line amounts
     *   net_pay          = gross_pay − total_deductions
     *
     * Basic and overtime pay are passed in (they live in their own columns); the
     * earning lines are the allowances only.
     *
     * @param  iterable<array{amount: int|float|string}>  $earnings
     * @param  iterable<array{amount: int|float|string}>  $deductions
     * @return array{total_earnings: float, gross_pay: float, total_deductions: float, net_pay: float}
     */
    public static function totals(float $basicPay, float $overtimePay, iterable $earnings, iterable $deductions): array
    {
        $sum = static function (iterable $lines): float {
            $total = 0.0;

            foreach ($lines as $line) {
                $total += (float) $line['amount'];
            }

            return round($total, 2);
        };

        $totalEarnings = $sum($earnings);
        $totalDeductions = $sum($deductions);
        $gross = round($basicPay + $overtimePay + $totalEarnings, 2);

        return [
            'total_earnings' => $totalEarnings,
            'gross_pay' => $gross,
            'total_deductions' => $totalDeductions,
            'net_pay' => round($gross - $totalDeductions, 2),
        ];
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

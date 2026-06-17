<?php

namespace App\Support\Payroll;

use App\Models\BenefitContribution;
use App\Models\DeductionType;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipDeduction;

/**
 * Derives a payroll run's statutory {@see BenefitContribution} rows (SSS /
 * PhilHealth / Pag-IBIG) from its payslips. The **employee** share is the
 * statutory deduction already on the payslip; the **employer** share — the company
 * counterpart that the payslip doesn't carry — is computed from the deduction
 * type's `employer` computation on the same gross. The result is the basis for the
 * monthly government remittance report.
 */
class BenefitContributionGenerator
{
    /**
     * (Re)generate the period's contributions and return how many rows were written.
     * Idempotent: the run's existing contributions are replaced.
     */
    public function generate(PayrollPeriod $period): int
    {
        BenefitContribution::query()->where('payroll_period_id', $period->id)->delete();

        $types = DeductionType::query()
            ->whereIn('kind', BenefitContribution::BENEFITS)
            ->where('is_mandatory', true)
            ->get()
            ->keyBy('kind');

        if ($types->isEmpty()) {
            return 0;
        }

        $periodLabel = $period->end_date->format('Y-m');

        $payslips = $period->payslips()
            ->with(['deductions.deductionType:id,kind', 'employee:id,basic_salary'])
            ->get();

        $count = 0;

        foreach ($payslips as $payslip) {
            $salary = (float) ($payslip->employee->basic_salary ?? 0);
            $gross = (float) $payslip->gross_pay;

            foreach (BenefitContribution::BENEFITS as $kind) {
                $type = $types->get($kind);

                if ($type === null) {
                    continue;
                }

                $employee = $this->employeeShare($payslip, $kind);
                $employer = $this->employerShare($type->computation, $salary, $gross);

                if ($employee <= 0 && $employer <= 0) {
                    continue;
                }

                BenefitContribution::create([
                    'employee_id' => $payslip->employee_id,
                    'payroll_period_id' => $period->id,
                    'period' => $periodLabel,
                    'benefit' => $kind,
                    'employee_share' => $employee,
                    'employer_share' => $employer,
                    'total' => round($employee + $employer, 2),
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * The employee share = the statutory deduction line already on the payslip.
     */
    private function employeeShare(Payslip $payslip, string $kind): float
    {
        $line = $payslip->deductions->first(
            fn (PayslipDeduction $deduction): bool => $deduction->deductionType?->kind === $kind
        );

        return $line ? round((float) $line->amount, 2) : 0.0;
    }

    /**
     * The employer counterpart, from the deduction type's `employer` computation
     * block (a rate/cap mirroring the employee config), on the same gross.
     *
     * @param  array<string, mixed>|null  $computation
     */
    private function employerShare(?array $computation, float $salary, float $gross): float
    {
        $config = is_array($computation) ? ($computation['employer'] ?? []) : [];

        return $config === []
            ? 0.0
            : PayrollCalculator::deductionAmount($config, $salary, $gross);
    }
}

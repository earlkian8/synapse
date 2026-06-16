<?php

namespace App\Support\Payroll;

use App\Models\AllowanceType;
use App\Models\AttendanceRecord;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * The canonical payroll run: generates (or regenerates) a {@see PayrollPeriod}'s
 * {@see Payslip}s from each active employee's basic salary and the attendance
 * recorded in the period window — so payroll reuses the DTR rather than
 * re-deriving time. Totals follow the contract:
 *
 *   total_earnings = Σ allowance earnings
 *   gross_pay      = basic_pay + overtime_pay + total_earnings
 *   net_pay        = gross_pay − total_deductions
 *
 * Math lives in {@see PayrollCalculator}; this class wires the data together.
 */
class PayrollProcessor
{
    /** Attendance statuses that count as a worked day for pay. */
    private const WORKED_STATUSES = ['present', 'late', 'undertime', 'incomplete'];

    /**
     * Build every payslip for the period and return how many were generated.
     * Re-processing is clean: existing payslips (and their lines, by cascade) are
     * replaced.
     */
    public function process(PayrollPeriod $period): int
    {
        // Replace any prior payslips so a re-run is idempotent (lines cascade).
        $period->payslips()->delete();

        $mandatory = DeductionType::query()->where('is_mandatory', true)->get();
        $allowanceTypes = AllowanceType::query()->get()->keyBy('name');

        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->whereNotNull('basic_salary')
            ->where('basic_salary', '>', 0)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $attendance = AttendanceRecord::query()
            ->whereBetween('work_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->get(['employee_id', 'status', 'overtime_minutes'])
            ->groupBy('employee_id');

        $count = 0;

        foreach ($employees as $employee) {
            $this->buildPayslip(
                $period,
                $employee,
                $attendance->get($employee->id) ?? collect(),
                $mandatory,
                $allowanceTypes,
            );
            $count++;
        }

        return $count;
    }

    /**
     * Compute and persist one employee's payslip with its itemised lines.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  Collection<int, DeductionType>  $mandatory
     * @param  Collection<string, AllowanceType>  $allowanceTypes
     */
    private function buildPayslip(
        PayrollPeriod $period,
        Employee $employee,
        Collection $records,
        Collection $mandatory,
        Collection $allowanceTypes,
    ): void {
        $salary = (float) $employee->basic_salary;

        $daysWorked = $records->whereIn('status', self::WORKED_STATUSES)->count();
        $overtimeHours = round($records->sum('overtime_minutes') / 60, 2);

        $basicPay = PayrollCalculator::basicPay($salary, $daysWorked);
        $overtimePay = PayrollCalculator::overtimePay($salary, $overtimeHours);

        $earnings = $this->defaultEarnings($employee, $allowanceTypes);
        $totalEarnings = round(collect($earnings)->sum('amount'), 2);

        $gross = round($basicPay + $overtimePay + $totalEarnings, 2);

        [$deductions, $totalDeductions] = $this->computeDeductions($mandatory, $salary, $gross);

        $net = round($gross - $totalDeductions, 2);

        $payslip = $period->payslips()->create([
            'employee_id' => $employee->id,
            'basic_pay' => $basicPay,
            'overtime_pay' => $overtimePay,
            'gross_pay' => $gross,
            'total_earnings' => $totalEarnings,
            'total_deductions' => $totalDeductions,
            'net_pay' => $net,
            'days_worked' => $daysWorked,
            'status' => 'draft',
        ]);

        foreach ($earnings as $earning) {
            $payslip->earnings()->create($earning);
        }

        foreach ($deductions as $deduction) {
            $payslip->deductions()->create($deduction);
        }
    }

    /**
     * The employee's allowance earnings for the period. Recurring per-employee
     * allowances (EMPLOYEE_ALLOWANCE) are deferred; until then, tenured staff get
     * the standard non-taxable allowances.
     *
     * @param  Collection<string, AllowanceType>  $allowanceTypes
     * @return list<array{allowance_type_id: int|null, label: string, amount: float}>
     */
    private function defaultEarnings(Employee $employee, Collection $allowanceTypes): array
    {
        if (! in_array($employee->employment_type, ['regular', 'probationary'], true)) {
            return [];
        }

        $earnings = [];

        foreach (['Rice Subsidy' => 2000.0, 'Meal Allowance' => 1500.0] as $name => $amount) {
            $earnings[] = [
                'allowance_type_id' => $allowanceTypes->get($name)?->id,
                'label' => $name,
                'amount' => $amount,
            ];
        }

        return $earnings;
    }

    /**
     * The mandatory deduction lines and their total. Non-tax statutory
     * contributions are computed first; withholding tax is then computed on the
     * gross net of those contributions.
     *
     * @param  Collection<int, DeductionType>  $mandatory
     * @return array{0: list<array{deduction_type_id: int, label: string, amount: float}>, 1: float}
     */
    private function computeDeductions(Collection $mandatory, float $salary, float $gross): array
    {
        $lines = [];
        $statutory = 0.0;

        foreach ($mandatory->where('kind', '!=', 'withholding_tax') as $type) {
            $amount = PayrollCalculator::deductionAmount($type->computation ?? [], $salary, $gross);

            if ($amount <= 0) {
                continue;
            }

            $lines[] = ['deduction_type_id' => $type->id, 'label' => $type->name, 'amount' => $amount];
            $statutory += $amount;
        }

        $taxable = max(0, $gross - $statutory);

        foreach ($mandatory->where('kind', 'withholding_tax') as $type) {
            $amount = PayrollCalculator::deductionAmount($type->computation ?? [], $salary, $taxable);

            if ($amount <= 0) {
                continue;
            }

            $lines[] = ['deduction_type_id' => $type->id, 'label' => $type->name, 'amount' => $amount];
        }

        return [$lines, round(collect($lines)->sum('amount'), 2)];
    }
}

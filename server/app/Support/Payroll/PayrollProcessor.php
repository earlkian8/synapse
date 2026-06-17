<?php

namespace App\Support\Payroll;

use App\Models\AttendanceRecord;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeDeduction;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The canonical payroll run: generates (or regenerates) a {@see PayrollPeriod}'s
 * {@see Payslip}s from each active employee's basic salary and the attendance
 * recorded in the period window — so payroll reuses the DTR rather than
 * re-deriving time. Per-employee allowances and recurring deductions
 * ({@see EmployeeAllowance} / {@see EmployeeDeduction}) drive the extra earning /
 * deduction lines, so Company-Setup config is meaningful end-to-end. Totals follow
 * the contract enforced by {@see PayrollCalculator::totals()}:
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
     * Re-processing is clean for auto payslips (they are replaced); payslips that
     * have been hand-adjusted ({@see Payslip::$is_adjusted}) are preserved
     * untouched so manual edits survive a re-run.
     */
    public function process(PayrollPeriod $period): int
    {
        // Hold back hand-adjusted payslips: leave them (and their lines) intact,
        // and don't regenerate one for those employees.
        $adjustedEmployeeIds = $period->payslips()
            ->where('is_adjusted', true)
            ->pluck('employee_id')
            ->all();

        $period->payslips()->where('is_adjusted', false)->delete();

        $mandatory = $this->mandatoryDeductions();

        $employees = $this->payableEmployees()
            ->whereNotIn('id', $adjustedEmployeeIds)
            ->get();

        $attendance = AttendanceRecord::query()
            ->whereBetween('work_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->whereIn('employee_id', $employees->modelKeys())
            ->get(['employee_id', 'status', 'overtime_minutes'])
            ->groupBy('employee_id');

        $count = 0;

        foreach ($employees as $employee) {
            $this->buildPayslip(
                $period,
                $employee,
                $attendance->get($employee->id) ?? collect(),
                $mandatory,
            );
            $count++;
        }

        return $count;
    }

    /**
     * (Re)generate the payslip for a single employee in a period — used to reset a
     * hand-adjusted payslip back to the auto figures. Returns null when the
     * employee is no longer payable (inactive / no salary), having removed any
     * stale payslip.
     */
    public function buildFor(PayrollPeriod $period, Employee $employee): ?Payslip
    {
        // Drop the current payslip (lines cascade) so this regenerates cleanly.
        $period->payslips()->where('employee_id', $employee->id)->delete();

        $payable = $this->payableEmployees()->whereKey($employee->id)->first();

        if ($payable === null) {
            return null;
        }

        $records = AttendanceRecord::query()
            ->where('employee_id', $payable->id)
            ->whereBetween('work_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->get(['employee_id', 'status', 'overtime_minutes']);

        return $this->buildPayslip($period, $payable, $records, $this->mandatoryDeductions());
    }

    /**
     * Compute and persist one employee's payslip with its itemised lines.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  Collection<int, DeductionType>  $mandatory
     */
    private function buildPayslip(
        PayrollPeriod $period,
        Employee $employee,
        Collection $records,
        Collection $mandatory,
    ): Payslip {
        $salary = (float) $employee->basic_salary;

        $daysWorked = $records->whereIn('status', self::WORKED_STATUSES)->count();
        $overtimeHours = round($records->sum('overtime_minutes') / 60, 2);

        $basicPay = PayrollCalculator::basicPay($salary, $daysWorked);
        $overtimePay = PayrollCalculator::overtimePay($salary, $overtimeHours);

        $earnings = $this->allowanceEarnings($employee);
        $totalEarnings = round(collect($earnings)->sum('amount'), 2);
        $gross = round($basicPay + $overtimePay + $totalEarnings, 2);

        // Mandatory statutory deductions, plus the employee's recurring deductions
        // (e.g. a loan) on top.
        $deductions = array_merge(
            $this->statutoryDeductions($mandatory, $salary, $gross),
            $this->recurringDeductionLines($employee),
        );

        $totals = PayrollCalculator::totals($basicPay, $overtimePay, $earnings, $deductions);

        $payslip = $period->payslips()->create([
            'employee_id' => $employee->id,
            'basic_pay' => $basicPay,
            'overtime_pay' => $overtimePay,
            'gross_pay' => $totals['gross_pay'],
            'total_earnings' => $totals['total_earnings'],
            'total_deductions' => $totals['total_deductions'],
            'net_pay' => $totals['net_pay'],
            'days_worked' => $daysWorked,
            'status' => 'draft',
            'is_adjusted' => false,
        ]);

        foreach ($earnings as $earning) {
            $payslip->earnings()->create($earning);
        }

        foreach ($deductions as $deduction) {
            $payslip->deductions()->create($deduction);
        }

        return $payslip;
    }

    /**
     * The employee's active recurring allowances as earning lines. Each line is
     * typed and carries the per-employee amount, so a Setup allowance type only
     * appears on a payslip once it is assigned to that employee (an archived type,
     * resolving to a null relation, is skipped).
     *
     * @return list<array{allowance_type_id: int|null, label: string, amount: float}>
     */
    private function allowanceEarnings(Employee $employee): array
    {
        return $employee->allowances
            ->filter(fn (EmployeeAllowance $allowance): bool => $allowance->allowanceType !== null && (float) $allowance->amount > 0)
            ->map(fn (EmployeeAllowance $allowance): array => [
                'allowance_type_id' => $allowance->allowance_type_id,
                'label' => $allowance->allowanceType->name,
                'amount' => round((float) $allowance->amount, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * The employee's active recurring deductions as deduction lines (loans etc.),
     * separate from the mandatory statutory contributions.
     *
     * @return list<array{deduction_type_id: int|null, label: string, amount: float}>
     */
    private function recurringDeductionLines(Employee $employee): array
    {
        return $employee->recurringDeductions
            ->filter(fn (EmployeeDeduction $deduction): bool => $deduction->deductionType !== null && (float) $deduction->amount > 0)
            ->map(fn (EmployeeDeduction $deduction): array => [
                'deduction_type_id' => $deduction->deduction_type_id,
                'label' => $deduction->deductionType->name,
                'amount' => round((float) $deduction->amount, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * The mandatory deduction lines. Non-tax statutory contributions are computed
     * first on the period's gross; withholding tax is then computed on the gross
     * net of those contributions.
     *
     * @param  Collection<int, DeductionType>  $mandatory
     * @return list<array{deduction_type_id: int, label: string, amount: float}>
     */
    private function statutoryDeductions(Collection $mandatory, float $salary, float $gross): array
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

        return $lines;
    }

    /**
     * The active, salaried employees a run pays, with their active recurring pay
     * items (and the lookups those resolve to) eager-loaded.
     *
     * @return Builder<Employee>
     */
    private function payableEmployees(): Builder
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->whereNotNull('basic_salary')
            ->where('basic_salary', '>', 0)
            ->with([
                'allowances' => fn ($query) => $query->where('is_active', true)->with('allowanceType:id,name'),
                'recurringDeductions' => fn ($query) => $query->where('is_active', true)->with('deductionType:id,name'),
            ])
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    /**
     * The tenant's mandatory deduction types.
     *
     * @return Collection<int, DeductionType>
     */
    private function mandatoryDeductions(): Collection
    {
        return DeductionType::query()->where('is_mandatory', true)->get();
    }
}

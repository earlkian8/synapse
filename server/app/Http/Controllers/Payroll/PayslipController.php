<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\UpdatePayslipRequest;
use App\Models\Payslip;
use App\Support\ActivityLogger;
use App\Support\Payroll\BenefitContributionGenerator;
use App\Support\Payroll\PayrollCalculator;
use App\Support\Payroll\PayrollProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Manual payslip adjustment. HR can open a generated payslip and edit its line
 * items; basic and overtime pay stay auto (their own columns), while the earning
 * (allowance) and deduction lines are replaced and the totals recomputed via
 * {@see PayrollCalculator::totals()}. An adjusted payslip is flagged so a
 * re-process leaves it untouched, and can be reset back to the auto figures.
 * Editing is blocked once the run is finalized / paid.
 */
class PayslipController extends Controller
{
    public function __construct(
        private readonly PayrollProcessor $processor,
        private readonly BenefitContributionGenerator $contributions,
    ) {}

    /**
     * Replace a payslip's manual lines and recompute its totals.
     */
    public function update(UpdatePayslipRequest $request, Payslip $payslip): RedirectResponse
    {
        $payslip->loadMissing('period', 'employee');

        if ($payslip->period->isLocked()) {
            return $this->respond('This run is locked and its payslips cannot be edited.', 'error');
        }

        $earnings = $request->validated('earnings');
        $deductions = $request->validated('deductions');

        $totals = PayrollCalculator::totals(
            (float) $payslip->basic_pay,
            (float) $payslip->overtime_pay,
            $earnings,
            $deductions,
        );

        DB::transaction(function () use ($payslip, $earnings, $deductions, $totals) {
            $payslip->earnings()->delete();
            $payslip->deductions()->delete();

            foreach ($earnings as $line) {
                $payslip->earnings()->create([
                    'allowance_type_id' => $line['allowance_type_id'] ?? null,
                    'label' => $line['label'],
                    'amount' => round((float) $line['amount'], 2),
                ]);
            }

            foreach ($deductions as $line) {
                $payslip->deductions()->create([
                    'deduction_type_id' => $line['deduction_type_id'] ?? null,
                    'label' => $line['label'],
                    'amount' => round((float) $line['amount'], 2),
                ]);
            }

            $payslip->update([
                'total_earnings' => $totals['total_earnings'],
                'gross_pay' => $totals['gross_pay'],
                'total_deductions' => $totals['total_deductions'],
                'net_pay' => $totals['net_pay'],
                'is_adjusted' => true,
            ]);
        });

        // Keep statutory contributions in step with the edited deduction lines.
        $this->contributions->generate($payslip->period);

        ActivityLogger::log(
            event: 'updated',
            description: "Adjusted payslip for {$payslip->employee?->full_name}",
            subject: $payslip,
            logName: 'payroll',
            subjectLabel: $payslip->employee?->full_name,
        );

        return $this->respond('Payslip adjusted.');
    }

    /**
     * Regenerate a single payslip from salary + attendance, clearing the manual
     * adjustment.
     */
    public function resetToAuto(Payslip $payslip): RedirectResponse
    {
        $payslip->loadMissing('period', 'employee');

        if ($payslip->period->isLocked()) {
            return $this->respond('This run is locked and its payslips cannot be reset.', 'error');
        }

        $name = $payslip->employee?->full_name;

        $this->processor->buildFor($payslip->period, $payslip->employee);
        $this->contributions->generate($payslip->period);

        ActivityLogger::log(
            event: 'updated',
            description: "Reset payslip to auto for {$name}",
            subject: $payslip->period,
            logName: 'payroll',
            subjectLabel: $name,
        );

        return $this->respond('Payslip reset to the auto-computed figures.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

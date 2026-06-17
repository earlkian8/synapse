<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StorePayrollPeriodRequest;
use App\Http\Resources\PayrollPeriodResource;
use App\Models\AllowanceType;
use App\Models\DeductionType;
use App\Models\PayrollPeriod;
use App\Support\ActivityLogger;
use App\Support\Payroll\PayrollProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The HR-facing payroll workspace — the list of payroll runs (each a pay period),
 * and a selected run's per-employee payslips. Pay figures are computed by
 * {@see PayrollProcessor} from salaries + attendance; the controller stays thin
 * (authorize, validate, delegate, return).
 */
class PayrollController extends Controller
{
    public function __construct(private readonly PayrollProcessor $processor) {}

    /**
     * The payroll runs overview: KPI summary + a card per pay period.
     */
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';

        $periods = PayrollPeriod::query()
            ->status($status)
            ->with('processor:id,first_name,middle_name,last_name,suffix')
            ->withCount('payslips')
            ->withSum('payslips as payslips_sum_gross_pay', 'gross_pay')
            ->withSum('payslips as payslips_sum_net_pay', 'net_pay')
            ->withSum('payslips as payslips_sum_total_deductions', 'total_deductions')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('payroll/index', [
            'periods' => PayrollPeriodResource::collection($periods)->resolve($request),
            'stats' => $this->stats(),
            'can' => $this->permissions($request),
            'filters' => ['status' => $status],
        ]);
    }

    /**
     * A single run with its per-employee payslips and itemised lines.
     */
    public function show(Request $request, PayrollPeriod $payrollPeriod): Response
    {
        $payrollPeriod->load([
            'processor:id,first_name,middle_name,last_name,suffix',
            'payslips.employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
            'payslips.employee.department:id,name',
            'payslips.employee.position:id,title',
            'payslips.earnings',
            'payslips.deductions.deductionType:id,kind',
        ]);

        return Inertia::render('payroll/show', [
            'period' => (new PayrollPeriodResource($payrollPeriod))->resolve($request),
            'can' => $this->permissions($request),
            // The Setup catalogues the manual payslip editor's pickers read.
            'catalogue' => [
                'allowanceTypes' => AllowanceType::orderBy('name')->get(['id', 'name']),
                'deductionTypes' => DeductionType::orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /**
     * Create a payroll run and immediately generate its payslips.
     */
    public function store(StorePayrollPeriodRequest $request): RedirectResponse
    {
        $period = PayrollPeriod::create([
            'name' => $request->string('name')->toString(),
            'start_date' => $request->date('start_date')->toDateString(),
            'end_date' => $request->date('end_date')->toDateString(),
            'pay_date' => $request->date('pay_date')->toDateString(),
            'status' => 'draft',
            'processed_by' => $request->user()->id,
        ]);

        $count = $this->processor->process($period);
        $period->update(['status' => 'processing']);

        ActivityLogger::log(
            event: 'created',
            description: "Processed payroll for {$period->name} — {$count} payslips",
            subject: $period,
            logName: 'payroll',
            subjectLabel: $period->name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Payroll processed — {$count} payslips generated."]);

        return redirect()->route('payroll.show', $period);
    }

    /**
     * Re-generate a run's payslips (e.g. after attendance corrections). Only while
     * the run is still open.
     */
    public function process(Request $request, PayrollPeriod $payrollPeriod): RedirectResponse
    {
        if ($payrollPeriod->isLocked()) {
            return $this->respond('This run is finalized and cannot be re-processed.', 'error');
        }

        $count = $this->processor->process($payrollPeriod);
        $payrollPeriod->update(['status' => 'processing', 'processed_by' => $request->user()->id]);

        ActivityLogger::log(
            event: 'updated',
            description: "Re-processed payroll for {$payrollPeriod->name} — {$count} payslips",
            subject: $payrollPeriod,
            logName: 'payroll',
            subjectLabel: $payrollPeriod->name,
        );

        return $this->respond("Payroll re-processed — {$count} payslips.");
    }

    /**
     * Finalize a run, locking its payslips from further changes.
     */
    public function finalize(PayrollPeriod $payrollPeriod): RedirectResponse
    {
        $payrollPeriod->update(['status' => 'finalized']);

        ActivityLogger::log(
            event: 'updated',
            description: "Finalized payroll for {$payrollPeriod->name}",
            subject: $payrollPeriod,
            logName: 'payroll',
            subjectLabel: $payrollPeriod->name,
        );

        return $this->respond('Payroll finalized.');
    }

    /**
     * Mark a finalized run as paid and release every payslip to its employee.
     */
    public function markPaid(PayrollPeriod $payrollPeriod): RedirectResponse
    {
        $payrollPeriod->update(['status' => 'paid']);
        $payrollPeriod->payslips()->update(['status' => 'released']);

        ActivityLogger::log(
            event: 'updated',
            description: "Marked payroll paid for {$payrollPeriod->name}",
            subject: $payrollPeriod,
            logName: 'payroll',
            subjectLabel: $payrollPeriod->name,
        );

        return $this->respond('Payroll marked as paid.');
    }

    /**
     * Delete a run (and its payslips via cascade). Not allowed once finalized.
     */
    public function destroy(PayrollPeriod $payrollPeriod): RedirectResponse
    {
        if ($payrollPeriod->isLocked()) {
            return $this->respond('Finalized runs cannot be deleted.', 'error');
        }

        $name = $payrollPeriod->name;
        $payrollPeriod->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted payroll run {$name}",
            logName: 'payroll',
            subjectLabel: $name,
        );

        return redirect()->route('payroll.index');
    }

    /**
     * Headline payroll metrics, taken from the most recent run.
     *
     * @return array<string, int|float>
     */
    private function stats(): array
    {
        $latest = PayrollPeriod::query()
            ->withCount('payslips')
            ->withSum('payslips as payslips_sum_net_pay', 'net_pay')
            ->withSum('payslips as payslips_sum_total_deductions', 'total_deductions')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        $pending = PayrollPeriod::query()->whereIn('status', ['draft', 'processing'])->count();

        return [
            'net' => (float) ($latest?->payslips_sum_net_pay ?? 0),
            'deductions' => (float) ($latest?->payslips_sum_total_deductions ?? 0),
            'headcount' => (int) ($latest?->payslips_count ?? 0),
            'pending' => $pending,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        return [
            'process' => $user->can('payroll.process'),
            'release' => $user->can('payroll.release'),
            'adjust' => $user->can('payroll.adjust'),
        ];
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}

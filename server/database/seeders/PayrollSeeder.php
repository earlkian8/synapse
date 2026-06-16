<?php

namespace Database\Seeders;

use App\Models\AllowanceType;
use App\Models\DeductionType;
use App\Models\Organization;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\PayrollProcessor;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo payroll: the statutory config (allowance / deduction types) plus a handful
 * of recent semi-monthly runs, processed from the seeded attendance so payslips
 * carry real, reconciling figures. Idempotent.
 */
class PayrollSeeder extends Seeder
{
    /**
     * Allowance types every organisation starts with (name => is_taxable).
     *
     * @var array<string, bool>
     */
    private const ALLOWANCES = [
        'Rice Subsidy' => false,
        'Meal Allowance' => false,
        'Transportation Allowance' => true,
    ];

    /**
     * Mandatory statutory deductions with their (approximate, PH-style)
     * computation config interpreted by {@see PayrollProcessor}.
     *
     * @var list<array{name: string, kind: string, computation: array<string, mixed>}>
     */
    private const DEDUCTIONS = [
        // Statutory contributions are computed on the period's gross (the
        // `taxable` base the processor passes), so they stay proportional to the
        // pay actually earned — a barely-worked cutoff never out-deducts itself.
        ['name' => 'SSS Contribution', 'kind' => 'sss', 'computation' => ['type' => 'rate', 'rate' => 0.045, 'base' => 'taxable', 'max' => 1350]],
        ['name' => 'PhilHealth', 'kind' => 'philhealth', 'computation' => ['type' => 'rate', 'rate' => 0.025, 'base' => 'taxable', 'max' => 2500]],
        ['name' => 'Pag-IBIG', 'kind' => 'pagibig', 'computation' => ['type' => 'rate', 'rate' => 0.02, 'base' => 'taxable', 'max' => 200]],
        ['name' => 'Withholding Tax', 'kind' => 'withholding_tax', 'computation' => [
            'type' => 'bracket',
            'brackets' => [
                ['over' => 20833, 'base' => 0, 'rate' => 0.15],
                ['over' => 33333, 'base' => 1875, 'rate' => 0.20],
                ['over' => 66667, 'base' => 8541.80, 'rate' => 0.25],
                ['over' => 166667, 'base' => 33541.80, 'rate' => 0.30],
                ['over' => 666667, 'base' => 183541.80, 'rate' => 0.35],
            ],
        ]],
    ];

    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        $this->seedConfig();

        if (PayrollPeriod::count() > 0) {
            return;
        }

        $processor = app(PayrollProcessor::class);
        $processedBy = User::where('email', 'dev@synapse.com')->value('id');

        $thisMonth = CarbonImmutable::today()->startOfMonth();
        $prevMonth = $thisMonth->subMonth();

        // Recent semi-monthly runs that overlap the seeded attendance window.
        $runs = [
            [$prevMonth, $prevMonth->setDay(15), 'paid'],
            [$prevMonth->setDay(16), $prevMonth->endOfMonth(), 'paid'],
            [$thisMonth, $thisMonth->setDay(15), 'finalized'],
            [$thisMonth->setDay(16), $thisMonth->endOfMonth(), 'processing'],
        ];

        foreach ($runs as [$start, $end, $status]) {
            $this->seedRun($processor, $start, $end, $status, $processedBy);
        }
    }

    /**
     * Seed the allowance / deduction config once.
     */
    private function seedConfig(): void
    {
        foreach (self::ALLOWANCES as $name => $isTaxable) {
            AllowanceType::firstOrCreate(['name' => $name], ['is_taxable' => $isTaxable]);
        }

        foreach (self::DEDUCTIONS as $deduction) {
            DeductionType::firstOrCreate(
                ['name' => $deduction['name']],
                ['kind' => $deduction['kind'], 'is_mandatory' => true, 'computation' => $deduction['computation']],
            );
        }
    }

    /**
     * Create one run, process its payslips, and land it on the given status.
     */
    private function seedRun(
        PayrollProcessor $processor,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $status,
        ?int $processedBy,
    ): void {
        $period = PayrollPeriod::create([
            'name' => $start->format('M j').'–'.$end->format('j, Y'),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'pay_date' => $end->addDays(5)->toDateString(),
            'status' => 'draft',
            'processed_by' => $processedBy,
        ]);

        $processor->process($period);

        $period->update(['status' => $status]);

        // Paid runs have their payslips released to employees.
        if ($status === 'paid') {
            $period->payslips()->update(['status' => 'released']);
        }
    }
}

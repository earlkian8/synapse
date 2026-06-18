<?php

namespace App\Http\Controllers\Benefits;

use App\Http\Controllers\Controller;
use App\Models\BenefitContribution;
use App\Support\Payroll\BenefitContributionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statutory contributions remittance report (ERD §7 `BENEFIT_CONTRIBUTION`): for a
 * remittance month, the SSS / PhilHealth / Pag-IBIG **employee + employer** shares
 * the company must remit, summarised per agency and broken down per employee.
 * Figures are derived from processed payroll runs (see
 * {@see BenefitContributionGenerator}).
 */
class BenefitContributionController extends Controller
{
    public function index(Request $request): Response
    {
        $periods = BenefitContribution::query()
            ->select('period')
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period');

        $selected = $request->string('period')->toString() ?: ($periods->first() ?? null);

        $contributions = $selected
            ? BenefitContribution::forPeriod($selected)
                ->with([
                    'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                    'employee.department:id,name',
                    'employee.position:id,title',
                ])
                ->get()
            : new Collection;

        return Inertia::render('benefits/contributions', [
            'periods' => $periods->map(fn (string $p): array => [
                'value' => $p,
                'label' => $this->monthLabel($p),
            ])->values(),
            'selected' => $selected,
            'summary' => $this->summary($contributions),
            'rows' => $this->rows($contributions),
            'can' => ['view' => true],
        ]);
    }

    /**
     * Per-agency totals + the grand total and distinct employee count.
     *
     * @param  Collection<int, BenefitContribution>  $contributions
     * @return array<string, mixed>
     */
    private function summary(Collection $contributions): array
    {
        $perBenefit = [];

        foreach (BenefitContribution::BENEFITS as $benefit) {
            $rows = $contributions->where('benefit', $benefit);
            $perBenefit[$benefit] = [
                'employee' => round((float) $rows->sum('employee_share'), 2),
                'employer' => round((float) $rows->sum('employer_share'), 2),
                'total' => round((float) $rows->sum('total'), 2),
            ];
        }

        return [
            'benefits' => $perBenefit,
            'employee' => round((float) $contributions->sum('employee_share'), 2),
            'employer' => round((float) $contributions->sum('employer_share'), 2),
            'total' => round((float) $contributions->sum('total'), 2),
            'employees' => $contributions->pluck('employee_id')->unique()->count(),
        ];
    }

    /**
     * One row per employee, with each agency's employee / employer share.
     *
     * @param  Collection<int, BenefitContribution>  $contributions
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $contributions): array
    {
        return $contributions
            ->groupBy('employee_id')
            ->map(function (Collection $rows) {
                $employee = $rows->first()->employee;

                $shares = [];
                foreach (BenefitContribution::BENEFITS as $benefit) {
                    $shares[$benefit] = [
                        'employee' => round((float) $rows->where('benefit', $benefit)->sum('employee_share'), 2),
                        'employer' => round((float) $rows->where('benefit', $benefit)->sum('employer_share'), 2),
                    ];
                }

                return [
                    'employee' => $employee ? [
                        'id' => $employee->id,
                        'full_name' => $employee->full_name,
                        'initials' => $employee->initials(),
                        'employee_no' => $employee->employee_no,
                        'photo' => $employee->photo_url,
                        'position' => $employee->position?->title,
                        'department' => $employee->department?->name,
                    ] : null,
                    'shares' => $shares,
                    'total' => round((float) $rows->sum('total'), 2),
                ];
            })
            ->sortBy(fn (array $row): string => $row['employee']['full_name'] ?? '')
            ->values()
            ->all();
    }

    /**
     * "2026-06" → "June 2026".
     */
    private function monthLabel(string $period): string
    {
        return CarbonImmutable::createFromFormat('Y-m', $period)->format('F Y');
    }
}

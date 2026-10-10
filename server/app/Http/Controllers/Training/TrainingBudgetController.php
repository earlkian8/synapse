<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingBudgetRequest;
use App\Models\TrainingCost;
use App\Support\OrganizationClock;
use App\Support\Training\TrainingBudgets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training money for a year (ADR 0075): the budget set for it against what was
 * spent — by category, by month and by program — with the costs behind it.
 * Viewing needs `training.view`; setting the budget `training.manage`.
 * `?year=` picks the year; it defaults to this one.
 */
class TrainingBudgetController extends Controller
{
    use TrainingResponses;

    public function index(Request $request): Response
    {
        $years = TrainingBudgets::years();
        $current = (int) substr(OrganizationClock::today(), 0, 4);
        $year = $request->integer('year', $current);
        $year = $year >= 2000 && $year <= 2100 ? $year : $current;

        $costs = TrainingCost::query()
            ->whereBetween('incurred_on', ["{$year}-01-01", "{$year}-12-31"])
            ->with(['program:id,name,deleted_at', 'recorder:id,first_name,last_name'])
            ->latest('incurred_on')
            ->latest('id')
            ->get();

        return Inertia::render('training/budget', [
            'summary' => TrainingBudgets::forYear($year),
            'years' => collect($years)->push($year)->unique()->sortDesc()->values()->all(),
            'costs' => $costs->map(fn (TrainingCost $c): array => [
                'id' => $c->id,
                'category' => $c->category,
                'description' => $c->description,
                'amount' => (float) $c->amount,
                'incurred_on' => $c->incurred_on->toDateString(),
                'recorded_by' => $c->recorder?->full_name,
                'program' => ['hashid' => $c->program?->hashid, 'name' => $c->program?->name, 'archived' => (bool) $c->program?->trashed()],
            ])->all(),
            'can' => ['manage' => $request->user()->can('training.manage')],
            'nav' => $this->navCounts($request),
        ]);
    }

    public function store(TrainingBudgetRequest $request, TrainingBudgets $budgets): RedirectResponse
    {
        $data = $request->validated();
        $budgets->setBudget((int) $data['year'], (float) $data['amount'], $data['notes'] ?? null);

        return $this->toast("{$data['year']} budget saved.");
    }
}

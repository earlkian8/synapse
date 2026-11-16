<?php

namespace App\Http\Controllers\Discipline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discipline\ConductOffenseRequest;
use App\Models\ConductOffense;
use App\Models\DisciplinaryCase;
use App\Queries\DisciplineScreens;
use App\Support\Discipline\ConductCode;
use App\Support\Discipline\DisciplineWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Discipline → Code of conduct (ADR 0105): the offenses a case is charged
 * under, each with its ground and its ladder of penalties. `discipline.manage`.
 */
class ConductCodeController extends Controller
{
    public function __construct(private readonly DisciplineWorkflow $workflow) {}

    public function index(DisciplineScreens $screens): Response
    {
        return Inertia::render('discipline/code', [
            'offenses' => $screens->offenses(inactive: true),
            'options' => [
                'gravities' => ConductOffense::GRAVITIES,
                'grounds' => ConductOffense::GROUNDS,
                'justCauses' => ConductOffense::JUST_CAUSES,
                'penalties' => DisciplinaryCase::PENALTIES,
            ],
        ]);
    }

    public function store(ConductOffenseRequest $request): RedirectResponse
    {
        $offense = $this->workflow->saveOffense(null, $request->validated());

        return $this->done("“{$offense->title}” added to the code of conduct.");
    }

    public function update(ConductOffenseRequest $request, ConductOffense $conductOffense): RedirectResponse
    {
        $this->workflow->saveOffense($conductOffense, $request->validated());

        return $this->done('Offense saved.');
    }

    /**
     * Remove an offense. Cases already charged under it keep naming it.
     */
    public function destroy(ConductOffense $conductOffense): RedirectResponse
    {
        $this->workflow->removeOffense($conductOffense);

        return $this->done("“{$conductOffense->title}” removed. Cases charged under it keep naming it.");
    }

    public function standard(): RedirectResponse
    {
        $added = ConductCode::addStandard();

        return $this->done($added > 0
            ? "{$added} standard offenses added. Edit them to match your handbook."
            : 'Your code already has every standard offense.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}

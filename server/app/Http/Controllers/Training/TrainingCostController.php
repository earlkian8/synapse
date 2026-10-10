<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingCostRequest;
use App\Models\TrainingCost;
use App\Models\TrainingProgram;
use App\Support\Training\TrainingBudgets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The costs recorded against a program (ADR 0075). Thin (route gate
 * `training.manage`), through {@see TrainingBudgets}. Costs are addressed by
 * numeric id.
 */
class TrainingCostController extends Controller
{
    use TrainingResponses;

    public function __construct(private readonly TrainingBudgets $budgets) {}

    public function store(TrainingCostRequest $request, TrainingProgram $trainingProgram): RedirectResponse
    {
        $this->budgets->record($trainingProgram, $request->validated(), $request->user());

        return $this->toast('Cost recorded.');
    }

    public function update(TrainingCostRequest $request, TrainingCost $cost): RedirectResponse
    {
        $this->budgets->update($cost, $request->validated());

        return $this->toast('Cost updated.');
    }

    public function destroy(Request $request, TrainingCost $cost): RedirectResponse
    {
        $this->budgets->delete($cost);

        return $this->toast('Cost removed.');
    }
}

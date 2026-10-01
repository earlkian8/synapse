<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Support\Setup\SetupBlueprints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adopting a checklist on the wizard's Onboarding or Offboarding step: which
 * blueprint, and optionally what the company calls it. The route decides which
 * list the key is resolved against — {@see SetupBlueprints::onboardingPrograms()}
 * or {@see SetupBlueprints::offboardingPrograms()} — so an onboarding key cannot
 * be posted as an exit clearance.
 *
 * What the checklist contains is the blueprint's; the program editor on the same
 * step is where a company makes it its own.
 */
class WizardProgramRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $blueprints = $this->routeIs('setup.wizard.offboarding')
            ? SetupBlueprints::offboardingPrograms()
            : SetupBlueprints::onboardingPrograms();

        return [
            'blueprint' => ['required', 'string', Rule::in(array_column($blueprints, 'key'))],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blueprint.required' => 'Pick a checklist, or skip this step.',
            'blueprint.in' => 'Pick a checklist, or skip this step.',
        ];
    }
}

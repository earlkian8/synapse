<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Support\Setup\SetupBlueprints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The award types the owner ticked on the wizard's Awards step — blueprint keys,
 * resolved by {@see SetupBlueprints::awardTypes()}. An award type of the
 * company's own is written in the award type editor on the same step.
 */
class WizardAwardTypesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['string', 'distinct', Rule::in(array_column(SetupBlueprints::awardTypes(), 'key'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keys.required' => 'Tick at least one award, or add your own.',
            'keys.min' => 'Tick at least one award, or add your own.',
            'keys.*.in' => 'That award is not one on offer.',
        ];
    }
}

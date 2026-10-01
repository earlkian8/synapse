<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Support\OrganizationClock;
use App\Support\Setup\SetupBlueprints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The holidays the owner ticked on the wizard's Schedules & Holidays step. Each
 * arrives as a blueprint key and is resolved — date included — by
 * {@see SetupBlueprints::holidays()}, so the client names a holiday but never
 * says when it falls.
 */
class WizardHolidaysRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['string', 'distinct', Rule::in(array_column(SetupBlueprints::holidays(OrganizationClock::now()), 'key'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keys.required' => 'Tick at least one holiday, or add your own.',
            'keys.min' => 'Tick at least one holiday, or add your own.',
            'keys.*.in' => 'That holiday is not one on offer.',
        ];
    }
}

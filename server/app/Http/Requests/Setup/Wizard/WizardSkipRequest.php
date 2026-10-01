<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Support\Setup\CompanySetup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Passing over one step of the wizard. Skipping is a real answer — it is recorded
 * as {@see CompanySetup::SKIPPED} so returning to the wizard resumes past it
 * rather than asking again.
 */
class WizardSkipRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'step' => ['required', 'string', Rule::in(CompanySetup::STEPS)],
        ];
    }
}

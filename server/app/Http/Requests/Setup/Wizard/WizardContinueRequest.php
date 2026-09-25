<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Support\Setup\CompanySetup;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Moving on from a step whose work was done with its own actions — records
 * created, edited or imported through the Company Setup editors the step
 * carries — rather than by adopting its suggestions.
 *
 * It records the step as done, which is only true when its module actually holds
 * something ({@see CompanySetup::configured()}); a step with nothing in it is
 * skipped, not completed. The person moving on must be able to configure the
 * step's module, as with every other step action.
 */
class WizardContinueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $step = $this->input('step');

        return ! is_string($step)
            || ! isset(CompanySetup::ABILITIES[$step])
            || $this->user()->can(CompanySetup::ABILITIES[$step]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'step' => ['required', 'string', Rule::in(CompanySetup::STEPS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $organization = app(Tenancy::class)->organization();
            $step = $this->input('step');

            if ($validator->errors()->has('step') || $organization === null) {
                return;
            }

            if (! CompanySetup::configured($organization)[$step]) {
                $validator->errors()->add('step', 'There is nothing here yet — add something, or skip this step.');
            }
        });
    }
}

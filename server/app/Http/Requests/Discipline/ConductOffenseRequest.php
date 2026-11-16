<?php

namespace App\Http\Requests\Discipline;

use App\Models\ConductOffense;
use App\Models\DisciplinaryCase;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An offense in the code of conduct (ADR 0105): its code, how grave, the Labor
 * Code ground, the ladder of penalties and how long one counts.
 */
class ConductOffenseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $offense = $this->route('conductOffense');

        return [
            'code' => ['required', 'string', 'max:20', TenantRule::unique('conduct_offenses', 'code')->ignore($offense?->id)->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'gravity' => ['required', Rule::in(array_keys(ConductOffense::GRAVITIES))],
            'ground' => ['required', Rule::in(array_keys(ConductOffense::GROUNDS))],
            'penalties' => ['required', 'array', 'min:1', 'max:8'],
            'penalties.*.action' => ['required', Rule::in(array_keys(DisciplinaryCase::PENALTIES))],
            'penalties.*.days' => ['nullable', 'integer', 'min:1', 'max:'.DisciplinaryCase::MAX_SUSPENSION_DAYS],
            'reckoning_months' => ['required', 'integer', 'min:1', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another offense already has this code.',
            'penalties.required' => 'Give the penalty for the first time at least.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('penalties', []) as $index => $step) {
                if (($step['action'] ?? null) === 'suspension' && empty($step['days'])) {
                    $validator->errors()->add("penalties.{$index}.days", 'Say how many days the suspension runs.');
                }

                if (($step['action'] ?? null) === 'dismissal' && $this->input('ground') === 'company_rule') {
                    $validator->errors()->add("penalties.{$index}.action", 'Dismissal needs a just cause under Article 297. Choose the ground it rests on, or a lesser penalty.');
                }
            }
        });
    }
}

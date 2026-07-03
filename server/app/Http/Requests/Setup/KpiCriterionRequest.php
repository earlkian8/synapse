<?php

namespace App\Http\Requests\Setup;

use App\Models\KpiCriterion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a KPI criterion (Company Setup → KPI & Evaluation Criteria).
 * The weight is the criterion's relative share of the overall score; the active
 * flag is normalised so an omitted switch reads as false.
 *
 * A criterion also carries its **rating scale** — how evaluators score it:
 *  - `points`      an N-point numeric scale (1 … `scale_max`, e.g. 1–5 or 1–10)
 *  - `percentage`  a 0–100 percentage
 *  - `scale`       named descriptive levels (label + value), covering letter
 *                  grades, competency bands, pass/fail, etc.
 *
 * The bounds (`scale_min` / `scale_max`) are derived here from the chosen type so
 * the client only sends what it needs and the stored scale is always coherent.
 */
class KpiCriterionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],

            'scale_type' => ['required', Rule::in(KpiCriterion::SCALE_TYPES)],
            'scale_min' => ['required', 'numeric'],
            'scale_max' => ['required', 'numeric', 'gt:scale_min', 'max:1000'],
            'scale_levels' => ['nullable', 'array', 'required_if:scale_type,scale', 'min:2', 'max:12'],
            'scale_levels.*.label' => ['required', 'string', 'max:60'],
            'scale_levels.*.value' => ['required', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scale_max.gt' => 'The rating scale needs a range — its top value must exceed its bottom value.',
            'scale_levels.required_if' => 'Add at least two rating levels.',
            'scale_levels.min' => 'A descriptive scale needs at least two levels.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = in_array($this->input('scale_type'), KpiCriterion::SCALE_TYPES, true)
            ? $this->input('scale_type')
            : 'points';

        $merge = ['is_active' => $this->boolean('is_active', true), 'scale_type' => $type];

        if ($type === 'percentage') {
            $merge += ['scale_min' => 0, 'scale_max' => 100, 'scale_levels' => null];
        } elseif ($type === 'scale') {
            $levels = $this->cleanLevels($this->input('scale_levels'));
            $values = array_column($levels, 'value');

            $merge += [
                'scale_levels' => $levels ?: null,
                'scale_min' => $values ? min($values) : 0,
                'scale_max' => $values ? max($values) : 0,
            ];
        } else {
            $max = (float) $this->input('scale_max', 5);
            $merge += ['scale_min' => 1, 'scale_max' => $max > 0 ? $max : 5, 'scale_levels' => null];
        }

        $this->merge($merge);
    }

    /**
     * Normalise the incoming descriptive levels into clean {label, value} rows,
     * dropping blanks so a stray empty row from the editor never persists.
     *
     * @return list<array{label: string, value: float}>
     */
    private function cleanLevels(mixed $levels): array
    {
        if (! is_array($levels)) {
            return [];
        }

        $clean = [];

        foreach ($levels as $level) {
            if (! is_array($level)) {
                continue;
            }

            $label = trim((string) ($level['label'] ?? ''));

            if ($label === '' || ! is_numeric($level['value'] ?? null)) {
                continue;
            }

            $clean[] = ['label' => $label, 'value' => (float) $level['value']];
        }

        return $clean;
    }
}

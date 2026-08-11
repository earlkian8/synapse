<?php

namespace App\Http\Requests\Setup;

use App\Support\Performance\RatingScales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a rating scale (Company Setup → Performance framework). A
 * scale is one of three instruments:
 *
 *  - `numeric`     a range with a granularity (1–5, 1–4, 1–10, halves…)
 *  - `percentage`  0–100, for goal attainment and quotas
 *  - `levels`      ordered named levels, each with a behavioural anchor
 *
 * The bounds are derived here from the chosen type, so the client sends only
 * what that type needs and what is stored is always coherent.
 */
class RatingScaleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(RatingScales::TYPES)],
            'min' => ['required', 'numeric', 'min:-1000', 'max:1000'],
            'max' => ['required', 'numeric', 'gt:min', 'max:1000'],
            'step' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'levels' => ['nullable', 'array', 'required_if:type,levels', 'min:2', 'max:12'],
            'levels.*.label' => ['required', 'string', 'max:60'],
            'levels.*.value' => ['required', 'numeric', 'min:-1000', 'max:1000'],
            'levels.*.description' => ['nullable', 'string', 'max:240'],
            'is_default' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max.gt' => 'A scale needs a range — its top value must be above its bottom value.',
            'levels.required_if' => 'A level scale needs its levels.',
            'levels.min' => 'A level scale needs at least two levels.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = in_array($this->input('type'), RatingScales::TYPES, true) ? $this->input('type') : 'numeric';
        $levels = RatingScales::normalizeLevels($this->input('levels'));

        $merge = ['type' => $type, 'is_default' => $this->boolean('is_default')];

        if ($type === 'percentage') {
            $merge += ['min' => 0, 'max' => 100, 'levels' => null];
        } elseif ($type === 'levels') {
            $values = array_column($levels ?? [], 'value');

            $merge += [
                'levels' => $levels,
                // With fewer than two usable levels the range is meaningless;
                // stand in a valid one so the error reported is the missing
                // level rather than a confusing second complaint about bounds.
                'min' => count($values) < 2 ? 0 : min($values),
                'max' => count($values) < 2 ? 1 : max($values),
                'step' => 1,
            ];
        } else {
            $merge += ['levels' => null];
        }

        $this->merge($merge);
    }
}

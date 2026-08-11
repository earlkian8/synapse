<?php

namespace App\Http\Requests\Setup;

use App\Models\ReviewTemplate;
use App\Support\Performance\RatingModel;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update an appraisal framework (Company Setup → Performance
 * framework). A framework arrives whole — its weighted sections, the items
 * inside them, its eligibility rule, and the rating model its results are
 * reported in — because those parts only make sense together.
 *
 * Keys for sections and bands are derived here rather than trusted, so renaming
 * a section in the editor cannot orphan the items that point at it, and the
 * cross-references are checked before anything is written.
 */
class ReviewTemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rating_scale_id' => ['nullable', 'integer', TenantRule::exists('rating_scales', 'id')],
            'result_display' => ['required', Rule::in(ReviewTemplate::RESULT_DISPLAYS)],

            'applies_to' => ['required', Rule::in(ReviewTemplate::APPLIES_TO)],
            'applies_to_values' => ['nullable', 'array', 'required_unless:applies_to,all', 'max:50'],
            'applies_to_values.*' => ['required', 'string', 'max:60'],

            'is_default' => ['boolean'],
            'is_active' => ['boolean'],

            'sections' => ['required', 'array', 'min:1', 'max:10'],
            'sections.*.key' => ['required', 'string', 'max:60'],
            'sections.*.name' => ['required', 'string', 'max:120'],
            'sections.*.description' => ['nullable', 'string', 'max:500'],
            'sections.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],

            'bands' => ['required', 'array', 'min:2', 'max:8'],
            'bands.*.key' => ['required', 'string', 'max:60'],
            'bands.*.label' => ['required', 'string', 'max:60'],
            'bands.*.min_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'bands.*.description' => ['nullable', 'string', 'max:240'],
            'bands.*.tone' => ['required', Rule::in(RatingModel::TONES)],

            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.kpi_criterion_id' => ['nullable', 'integer', TenantRule::exists('kpi_criteria', 'id')],
            'items.*.rating_scale_id' => ['nullable', 'integer', TenantRule::exists('rating_scales', 'id')],
            'items.*.section_key' => ['required', 'string', 'max:60'],
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sections.required' => 'A framework needs at least one section.',
            'bands.min' => 'A rating model needs at least two bands.',
            'items.required' => 'A framework needs at least one thing to measure.',
            'applies_to_values.required_unless' => 'Choose who this framework applies to.',
        ];
    }

    /**
     * Every item must sit in a section this framework actually declares, and the
     * lowest band must start at zero — otherwise a result can fall through the
     * rating model and come back unlabelled.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $keys = array_column($this->input('sections', []), 'key');

            foreach ($this->input('items', []) as $index => $item) {
                if (! in_array($item['section_key'] ?? null, $keys, true)) {
                    $validator->errors()->add("items.{$index}.section_key", 'This item is not in one of the framework’s sections.');
                }
            }

            $bands = $this->input('bands', []);
            $floor = $bands === [] ? null : min(array_map(fn (array $band): float => (float) ($band['min_percent'] ?? 0), $bands));

            if ($floor !== null && $floor > 0) {
                $validator->errors()->add('bands', 'The lowest band has to start at 0%, so every result has a rating.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_default' => $this->boolean('is_default'),
            'is_active' => $this->boolean('is_active', true),
            'applies_to_values' => $this->input('applies_to') === 'all'
                ? null
                : array_values(array_map(strval(...), (array) $this->input('applies_to_values', []))),
            'sections' => $this->keyed($this->input('sections'), 'name', 'section'),
            'bands' => $this->keyed($this->input('bands'), 'label', 'band'),
        ]);
    }

    /**
     * Stamp a stable, unique key on each row from its own label. Rows keep any
     * key they already carry so an edit does not re-point the items at a renamed
     * section; new rows are slugged, and collisions are suffixed.
     *
     * @return list<array<string, mixed>>
     */
    private function keyed(mixed $rows, string $labelField, string $prefix): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $keyed = [];
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));

            if ($key === '') {
                $key = Str::slug((string) ($row[$labelField] ?? ''), '_') ?: "{$prefix}_{$index}";
            }

            while (in_array($key, $seen, true)) {
                $key .= '_2';
            }

            $seen[] = $key;
            $row['key'] = $key;

            if ($prefix === 'band' && ! in_array($row['tone'] ?? null, RatingModel::TONES, true)) {
                $row['tone'] = 'neutral';
            }

            $keyed[] = $row;
        }

        return $keyed;
    }
}

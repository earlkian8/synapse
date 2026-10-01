<?php

namespace App\Http\Requests\Setup;

use App\Models\KpiCriterion;
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
 * cross-references are checked before anything is written. An item drawing from
 * the criteria catalogue also has its wording taken from the catalogue, so a
 * framework can never ask a question the criterion it names no longer stands for.
 */
class ReviewTemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::documentRules();
    }

    /**
     * The rules a framework document is held to. Static so a caller with no
     * request — the assistant — holds one to exactly these.
     *
     * @return array<string, mixed>
     */
    public static function documentRules(): array
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
     * Every item must sit in a section this framework actually declares, no
     * criterion may be measured twice (that only splits its weight in two), and
     * the lowest band must start at zero — otherwise a result can fall through
     * the rating model and come back unlabelled.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => self::validateDocument($validator, $this->all()));
    }

    /**
     * The cross-references {@see withValidator()} checks, on a document that has
     * been through {@see normalise()}.
     *
     * @param  array<string, mixed>  $input
     */
    public static function validateDocument(Validator $validator, array $input): void
    {
        $keys = array_column((array) ($input['sections'] ?? []), 'key');
        $seen = [];

        foreach ((array) ($input['items'] ?? []) as $index => $item) {
            if (! in_array($item['section_key'] ?? null, $keys, true)) {
                $validator->errors()->add("items.{$index}.section_key", 'This item is not in one of the framework’s sections.');
            }

            $criterion = $item['kpi_criterion_id'] ?? null;

            if ($criterion === null) {
                continue;
            }

            if (in_array($criterion, $seen, true)) {
                $validator->errors()->add("items.{$index}.kpi_criterion_id", 'This criterion is already measured in this framework.');
            }

            $seen[] = $criterion;
        }

        $bands = (array) ($input['bands'] ?? []);
        $floor = $bands === [] ? null : min(array_map(fn (array $band): float => (float) ($band['min_percent'] ?? 0), $bands));

        if ($floor !== null && $floor > 0) {
            $validator->errors()->add('bands', 'The lowest band has to start at 0%, so every result has a rating.');
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::normalise($this->all()));
    }

    /**
     * A framework document as it is validated and stored: the flags coerced,
     * the eligibility values as strings, every section and band keyed, and every
     * catalogue-backed item worded by its catalogue entry. Static so a caller
     * with no request builds exactly the document the editor would.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        return [
            ...$input,
            'is_default' => filter_var($input['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_active' => filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'applies_to_values' => ($input['applies_to'] ?? null) === 'all'
                ? null
                : array_values(array_map(strval(...), (array) ($input['applies_to_values'] ?? []))),
            'sections' => self::keyed($input['sections'] ?? null, 'name', 'section'),
            'bands' => self::keyed($input['bands'] ?? null, 'label', 'band'),
            'items' => self::catalogued($input['items'] ?? null),
        ];
    }

    /**
     * Take each catalogue-backed item's wording from the catalogue. The editor
     * offers criteria as a choice rather than a free-text box, and the same has
     * to hold for anything else posting here: otherwise a line can carry the name
     * of one criterion while counting as another, which is invisible everywhere
     * the two are read together. A one-off line — no criterion named — keeps the
     * words it was written with.
     *
     * @return list<array<string, mixed>>
     */
    private static function catalogued(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $rows = array_values(array_filter($rows, is_array(...)));

        $ids = array_values(array_filter(array_map(
            fn (array $row): ?int => is_numeric($row['kpi_criterion_id'] ?? null)
                ? (int) $row['kpi_criterion_id']
                : null,
            $rows,
        )));

        // Archived criteria still name the lines already drawing on them, so the
        // trashed ones are read too rather than silently losing their wording.
        $criteria = $ids === []
            ? collect()
            : KpiCriterion::withTrashed()->whereKey($ids)->get()->keyBy('id');

        return array_map(function (array $row) use ($criteria): array {
            $criterion = $criteria->get((int) ($row['kpi_criterion_id'] ?? 0));

            if ($criterion instanceof KpiCriterion) {
                $row['name'] = $criterion->name;
                $row['description'] = $criterion->description;
            }

            return $row;
        }, $rows);
    }

    /**
     * Stamp a stable, unique key on each row from its own label. Rows keep any
     * key they already carry so an edit does not re-point the items at a renamed
     * section; new rows are slugged, and collisions are suffixed.
     *
     * @return list<array<string, mixed>>
     */
    private static function keyed(mixed $rows, string $labelField, string $prefix): array
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

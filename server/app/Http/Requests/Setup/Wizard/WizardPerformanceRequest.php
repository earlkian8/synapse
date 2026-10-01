<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Http\Requests\Setup\ReviewTemplateRequest;
use App\Models\ReviewTemplate;
use App\Support\Performance\RatingModel;
use App\Support\Setup\SetupBlueprints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The wizard's appraisal step: what the company reviews its people against.
 *
 * A framework is a whole apparatus — weighted sections, the criteria inside
 * them, the instruments those are measured on, and the words a result is
 * reported in (ADR 0028) — so the step offers one to **adopt**, resolved from
 * {@see SetupBlueprints} by key. A company whose appraisal does not look like
 * any of them can **design its own** instead, and that arrives here whole: it is
 * held to the same shape {@see ReviewTemplateRequest}
 * enforces on the Performance Framework screen, so the wizard cannot build a
 * framework that screen would refuse to save.
 *
 * Two things stay the server's either way. A criterion drawn from the catalogue
 * is named by **key** and takes its wording from
 * {@see SetupBlueprints::criteria()} — the same rule the framework editor works
 * by, so a line can never carry the name of one criterion while counting as
 * another. And an instrument is named out of {@see SetupBlueprints::instruments()}
 * rather than described: a scale has bounds and anchors that the whole scoring
 * apparatus reads, and the screen that designs one properly is one click away
 * once setup is done.
 */
class WizardPerformanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->input('source') !== 'custom') {
            return [
                'source' => ['required', Rule::in(['blueprint', 'custom'])],
                'blueprint' => ['required', 'string', Rule::in(array_column(SetupBlueprints::frameworks(), 'key'))],
                'name' => ['nullable', 'string', 'max:120'],
            ];
        }

        $instruments = array_column(SetupBlueprints::instruments(), 'name');

        return [
            'source' => ['required', Rule::in(['blueprint', 'custom'])],

            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scale' => ['required', 'string', Rule::in($instruments)],
            'result_display' => ['required', Rule::in(ReviewTemplate::RESULT_DISPLAYS)],

            'sections' => ['required', 'array', 'min:1', 'max:10'],
            'sections.*.key' => ['required', 'string', 'max:60'],
            'sections.*.name' => ['required', 'string', 'max:120'],
            'sections.*.description' => ['nullable', 'string', 'max:500'],
            'sections.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],

            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.section' => ['required', 'string', 'max:60'],
            'items.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            // A line either names a catalogue criterion, or is one the company
            // wrote — which is why both sides are nullable and the pair is
            // checked below rather than here.
            'items.*.criterion' => ['nullable', 'string', Rule::in(array_keys(SetupBlueprints::criteria()))],
            'items.*.name' => ['nullable', 'string', 'max:160'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.scale' => ['nullable', 'string', Rule::in($instruments)],

            // Left out entirely, the standard ladder is used.
            'bands' => ['nullable', 'array', 'min:2', 'max:8'],
            'bands.*.label' => ['required', 'string', 'max:60'],
            'bands.*.min_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'bands.*.description' => ['nullable', 'string', 'max:240'],
            'bands.*.tone' => ['required', Rule::in(RatingModel::TONES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.required' => 'Pick a framework, or skip this step.',
            'blueprint.required' => 'Pick a framework, or skip this step.',
            'blueprint.in' => 'Pick a framework, or skip this step.',
            'name.required' => 'Give your framework a name.',
            'sections.required' => 'A framework needs at least one section.',
            'items.required' => 'A framework needs at least one thing to measure.',
            'bands.min' => 'A rating model needs at least two bands.',
        ];
    }

    /**
     * The cross-references no per-field rule can see: every line has to say what
     * it measures and sit in a section this framework declares, no criterion may
     * be measured twice (that only splits its weight in two), and the lowest
     * band has to start at zero — otherwise a result can fall through the rating
     * model and come back unlabelled.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('source') !== 'custom') {
                return;
            }

            $keys = array_column((array) $this->input('sections', []), 'key');

            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add('sections', 'Two sections cannot share the same key.');
            }

            $seen = [];

            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! in_array($item['section'] ?? null, $keys, true)) {
                    $validator->errors()->add("items.{$index}.section", 'This criterion is not in one of the framework’s sections.');
                }

                $criterion = $item['criterion'] ?? null;

                if ($criterion === null) {
                    if (trim((string) ($item['name'] ?? '')) === '') {
                        $validator->errors()->add("items.{$index}.name", 'Name what this measures, or pick one from the list.');
                    }

                    continue;
                }

                if (in_array($criterion, $seen, true)) {
                    $validator->errors()->add("items.{$index}.criterion", 'This criterion is already measured in this framework.');
                }

                $seen[] = $criterion;
            }

            $bands = (array) $this->input('bands', []);

            if ($bands !== [] && min(array_map(fn (array $band): float => (float) ($band['min_percent'] ?? 0), $bands)) > 0) {
                $validator->errors()->add('bands', 'The lowest band has to start at 0%, so every result has a rating.');
            }
        });
    }

    /**
     * Drop the rows a company started and left blank — an empty section or an
     * unanswered line is an abandoned thought rather than a validation failure —
     * and settle the source before any rule reads it.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['source' => $this->input('source', 'blueprint')]);

        if ($this->input('source') !== 'custom') {
            return;
        }

        $sections = array_values(array_filter(
            (array) $this->input('sections', []),
            fn (mixed $section): bool => is_array($section) && trim((string) ($section['name'] ?? '')) !== '',
        ));

        $keys = array_column($sections, 'key');

        $items = array_values(array_filter(
            (array) $this->input('items', []),
            fn (mixed $item): bool => is_array($item)
                && in_array($item['section'] ?? null, $keys, true)
                && (($item['criterion'] ?? null) !== null || trim((string) ($item['name'] ?? '')) !== ''),
        ));

        $bands = array_values(array_filter(
            (array) $this->input('bands', []),
            fn (mixed $band): bool => is_array($band) && trim((string) ($band['label'] ?? '')) !== '',
        ));

        $this->merge([
            'sections' => $sections,
            'items' => $items,
            // Null rather than an empty list, so "I left the rating model alone"
            // reads as absence instead of a model with no bands in it.
            'bands' => $bands === [] ? null : $bands,
        ]);
    }
}

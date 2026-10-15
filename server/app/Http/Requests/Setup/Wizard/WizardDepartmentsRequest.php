<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Http\Requests\Setup\DepartmentRequest;
use App\Http\Requests\Setup\PositionRequest;
use App\Models\Department;
use App\Support\Setup\SetupBlueprints;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The wizard's org-structure step: the suggested departments the owner ticked,
 * plus any they described for themselves. A suggestion is sent as a blueprint
 * code and resolved server-side (see {@see SetupBlueprints}), so its wording is
 * never something the client gets to decide; a department the company wrote —
 * including a suggestion it customised, which arrives as one of these — carries
 * its own name, code and description, validated exactly as
 * {@see DepartmentRequest} would.
 *
 * Every department arrives with the positions to open under it: `positions`
 * keyed by a ticked suggestion's code, and `custom.*.positions` for the
 * company's own. A position is only a title here — salary grades are set on
 * the board below — held to {@see PositionRequest}'s length.
 */
class WizardDepartmentsRequest extends FormRequest
{
    /** How many positions one department may bring in this step. */
    public const MAX_POSITIONS = 30;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $codes = array_column(SetupBlueprints::departments(), 'code');
        $orgId = app(Tenancy::class)->id();

        $unique = Rule::unique('departments', 'code')
            ->where(fn ($query) => $query->where('organization_id', $orgId)->whereNull('deleted_at'));

        return [
            'codes' => ['present', 'array'],
            'codes.*' => ['string', Rule::in($codes)],

            'custom' => ['present', 'array', 'max:20'],
            'custom.*.name' => ['required', 'string', 'max:255'],
            'custom.*.code' => ['required', 'string', 'max:50', $unique],
            'custom.*.description' => ['nullable', 'string', 'max:2000'],
            'custom.*.positions' => ['array', 'max:'.self::MAX_POSITIONS],
            'custom.*.positions.*' => ['string', 'max:255'],

            'positions' => ['array'],
            'positions.*' => ['array', 'max:'.self::MAX_POSITIONS],
            'positions.*.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom.*.code.unique' => 'This company already has a department with that code.',
        ];
    }

    /**
     * A step that creates nothing is a skip, and the wizard has a button for
     * that — so ask for at least one department here. Codes also have to be
     * distinct within the submission itself, which no per-row rule can see.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $codes = (array) $this->input('codes', []);
            $custom = (array) $this->input('custom', []);

            if ($codes === [] && $custom === []) {
                $validator->errors()->add('codes', 'Pick at least one department, or skip this step.');

                return;
            }

            // Positions belong to a department being added here, by its code.
            foreach (array_keys((array) $this->input('positions', [])) as $code) {
                if (! in_array($code, $codes, true)) {
                    $validator->errors()->add('positions', 'Positions can only be added to a department you are adding.');

                    break;
                }
            }

            $seen = $codes;

            foreach ($custom as $index => $row) {
                $code = $row['code'] ?? null;

                if ($code === null) {
                    continue;
                }

                if (in_array($code, $seen, true)) {
                    $validator->errors()->add("custom.{$index}.code", 'This code is already used by another department in this list.');
                }

                $seen[] = $code;
            }
        });
    }

    /**
     * Normalise the typed codes the way {@see Department} stores them (trimmed,
     * uppercase) before anything compares them — to each other, or to what the
     * company already has.
     */
    protected function prepareForValidation(): void
    {
        $custom = [];

        foreach ((array) $this->input('custom', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $code = strtoupper(trim((string) ($row['code'] ?? '')));

            $description = trim((string) ($row['description'] ?? ''));

            $custom[] = [
                'name' => $name,
                // An empty code is derivable rather than an error: the name is
                // what the owner meant, the code is bookkeeping.
                'code' => $code !== '' ? $code : Str::upper(Str::limit(Str::slug($name, ''), 8, '')),
                'description' => $description !== '' ? $description : null,
                'positions' => self::titles($row['positions'] ?? []),
            ];
        }

        $positions = [];

        foreach ((array) $this->input('positions', []) as $code => $titles) {
            $titles = self::titles($titles);

            if ($titles !== []) {
                $positions[(string) $code] = $titles;
            }
        }

        $this->merge([
            'codes' => array_values(array_unique(array_map(strval(...), (array) $this->input('codes', [])))),
            'custom' => $custom,
            'positions' => $positions,
        ]);
    }

    /**
     * Position titles as they will be saved: trimmed, blanks dropped, and each
     * title once however it was capitalised.
     *
     * @return list<string>
     */
    private static function titles(mixed $titles): array
    {
        $kept = [];

        foreach ((array) $titles as $title) {
            $title = trim(is_scalar($title) ? (string) $title : '');

            if ($title !== '' && ! in_array(mb_strtolower($title), array_map(mb_strtolower(...), $kept), true)) {
                $kept[] = $title;
            }
        }

        return $kept;
    }
}

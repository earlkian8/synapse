<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Http\Requests\Setup\LeaveTypeRequest;
use App\Models\LeaveType;
use App\Support\Setup\SetupBlueprints;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The wizard's leave step: which kinds of leave the company grants, and what
 * each one carries.
 *
 * A **suggested** type is sent as a blueprint code with only its entitlement
 * attached — its name, colour and policy flags come from
 * {@see SetupBlueprints}, because a statutory leave is not a thing a client
 * should be able to redefine on the way in.
 *
 * A **company's own** type — written from scratch, or a suggestion it decided to
 * change before adopting — arrives whole, and is held to the same rules
 * {@see LeaveTypeRequest} applies on the Leave Types screen: the wizard must not
 * be able to create a leave type that screen would reject.
 */
class WizardLeaveTypesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $codes = array_column(SetupBlueprints::leaveTypes(), 'code');
        $orgId = app(Tenancy::class)->id();

        $unique = Rule::unique('leave_types', 'code')
            ->where(fn ($query) => $query->where('organization_id', $orgId)->whereNull('deleted_at'));

        return [
            'codes' => ['present', 'array'],
            'codes.*' => ['string', Rule::in($codes)],

            // Keyed by blueprint code. Bounds match LeaveTypeRequest so the
            // wizard cannot create a type the leave-types screen would reject.
            'days' => ['present', 'array'],
            'days.*' => ['numeric', 'min:0', 'max:365'],

            'custom' => ['present', 'array', 'max:20'],
            'custom.*.name' => ['required', 'string', 'max:255'],
            'custom.*.code' => ['required', 'string', 'max:20', $unique],
            'custom.*.description' => ['nullable', 'string', 'max:2000'],
            'custom.*.color' => ['required', 'string', 'max:20'],
            'custom.*.default_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'custom.*.is_paid' => ['boolean'],
            'custom.*.allow_half_day' => ['boolean'],
            'custom.*.requires_approval' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom.*.code.unique' => 'This company already has a leave type with that code.',
        ];
    }

    /**
     * A step that creates nothing is a skip, and the wizard has a button for
     * that. Codes also have to be distinct across the whole submission — a
     * customised suggestion and the suggestion it came from would otherwise both
     * claim "VL", which no per-row rule can see.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $codes = (array) $this->input('codes', []);
            $custom = (array) $this->input('custom', []);

            if ($codes === [] && $custom === []) {
                $validator->errors()->add('codes', 'Pick at least one kind of leave, or skip this step.');

                return;
            }

            $seen = $codes;

            foreach ($custom as $index => $row) {
                $code = $row['code'] ?? null;

                if ($code === null) {
                    continue;
                }

                if (in_array($code, $seen, true)) {
                    $validator->errors()->add("custom.{$index}.code", 'This code is already used by another kind of leave in this list.');
                }

                $seen[] = $code;
            }
        });
    }

    /**
     * The days the owner settled on, as blueprint code => entitlement. Days for a
     * type that was not ticked are dropped rather than validated — an untouched
     * field on an unticked row is not an error.
     *
     * @return array<string, float>
     */
    public function days(): array
    {
        $codes = $this->validated('codes');
        $days = [];

        foreach ((array) $this->validated('days') as $code => $value) {
            if (in_array($code, $codes, true)) {
                $days[(string) $code] = (float) $value;
            }
        }

        return $days;
    }

    /**
     * Normalise the typed codes the way {@see LeaveType} stores them
     * (trimmed, uppercase) and coerce the policy flags, before anything compares
     * them — to each other, or to what the company already has.
     */
    /**
     * A code for a leave type that was left without one, in the shape leave
     * codes actually take: the initials of a name that has several words
     * ("Typhoon Leave" → TL), the first three letters of one that does not
     * ("Sabbatical" → SAB). Truncating the whole name instead would produce
     * "TYPHOO", which is nobody's idea of a code.
     */
    private static function codeFor(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) > 1) {
            return Str::upper(implode('', array_map(fn (string $word): string => $word[0], $words)));
        }

        return Str::upper(Str::substr($words[0] ?? $name, 0, 3));
    }

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

            $custom[] = [
                'name' => $name,
                // An empty code is derivable rather than an error: the name is
                // what the owner meant, the code is bookkeeping.
                'code' => $code !== '' ? $code : self::codeFor($name),
                'description' => trim((string) ($row['description'] ?? '')) ?: null,
                'color' => trim((string) ($row['color'] ?? '')) ?: '#0ABFBF',
                'default_days' => $row['default_days'] ?? 0,
                'is_paid' => filter_var($row['is_paid'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'allow_half_day' => filter_var($row['allow_half_day'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'requires_approval' => filter_var($row['requires_approval'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        $this->merge([
            'codes' => array_values(array_unique(array_map(strval(...), (array) $this->input('codes', [])))),
            'days' => array_filter(
                (array) $this->input('days', []),
                fn (mixed $value): bool => is_numeric($value),
            ),
            'custom' => $custom,
        ]);
    }
}

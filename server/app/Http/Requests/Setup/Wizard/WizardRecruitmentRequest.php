<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Http\Requests\Recruitment\StoreRecruitmentPipelineRequest;
use App\Models\RecruitmentPipelineStage;
use App\Support\Setup\SetupBlueprints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The wizard's hiring step: the process job postings will run candidates
 * through.
 *
 * It can be answered two ways. **Adopt** one of the shapes in
 * {@see SetupBlueprints} — the stages are then resolved server-side rather than
 * posted, so the open/won/lost semantics recruitment depends on (ADR 0029) are
 * guaranteed correct on the way in. Or **draw one**: the company names its own
 * stages and says what each means, held to the same rules
 * {@see StoreRecruitmentPipelineRequest} applies
 * on the Recruitment Pipelines screen — exactly one hired stage, at least one
 * rejected one. A stage's *kind* is still resolved against
 * {@see RecruitmentPipelineStage::KINDS} either way; only its wording is the
 * company's.
 */
class WizardRecruitmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $custom = $this->input('source') === 'custom';

        return [
            'source' => ['required', Rule::in(['blueprint', 'custom'])],

            'blueprint' => $custom
                ? ['nullable', 'string']
                : ['required', 'string', Rule::in(array_column(SetupBlueprints::pipelines(), 'key'))],

            // A blueprint may be renamed; a process the company drew has to be
            // called something, because there is no blueprint name behind it.
            'name' => $custom
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],

            'stages' => $custom
                ? ['required', 'array', 'min:1', 'max:20']
                : ['array', 'max:20'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.kind' => ['required', Rule::in(RecruitmentPipelineStage::KINDS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.required' => 'Pick a hiring process, or skip this step.',
            'blueprint.required' => 'Pick a hiring process, or skip this step.',
            'blueprint.in' => 'Pick a hiring process, or skip this step.',
            'name.required' => 'Give your hiring process a name.',
            'stages.required' => 'A hiring process needs at least one stage.',
        ];
    }

    /**
     * The two things every other part of recruitment assumes exist: somewhere a
     * candidate ends up hired, and somewhere they end up not. Checked only for a
     * process the company drew — a blueprint's stages never reach the request.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('source') !== 'custom') {
                return;
            }

            $stages = collect($this->input('stages', []));

            if ($stages->where('kind', 'won')->count() !== 1) {
                $validator->errors()->add('stages', 'A hiring process needs exactly one stage that means hired.');
            }

            if ($stages->where('kind', 'lost')->count() < 1) {
                $validator->errors()->add('stages', 'A hiring process needs at least one stage that means the candidate did not go through.');
            }
        });
    }

    /**
     * Drop the stages a company started typing and left blank — a half-written
     * row is an abandoned thought, not a validation failure.
     */
    protected function prepareForValidation(): void
    {
        $stages = [];

        foreach ((array) $this->input('stages', []) as $stage) {
            if (! is_array($stage) || trim((string) ($stage['name'] ?? '')) === '') {
                continue;
            }

            $stages[] = [
                'name' => trim((string) $stage['name']),
                'kind' => $stage['kind'] ?? 'open',
            ];
        }

        $this->merge([
            'source' => $this->input('source', 'blueprint'),
            'stages' => $stages,
        ]);
    }
}

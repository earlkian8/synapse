<?php

namespace App\Http\Requests\Recruitment;

use App\Models\RecruitmentPipeline;
use App\Models\RecruitmentPipelineStage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a recruitment pipeline together with its ordered stages. The
 * full stage list is sent each save and synced wholesale (see
 * {@see RecruitmentPipeline::syncStages()}), same principle as
 * onboarding programs and their blueprint tasks.
 *
 * A stage that is kept carries its `id`, which must be one of *this* pipeline's
 * stages: a stage without one is a new stage, and every existing stage not named
 * is dropped — so an id from elsewhere would silently lose a stage.
 */
class StoreRecruitmentPipelineRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pipeline = $this->route('pipeline');

        return self::rulesFor($pipeline instanceof RecruitmentPipeline ? $pipeline : null);
    }

    /**
     * The rules for creating a pipeline (null) or editing this one. Static so a
     * caller with no route — the assistant — holds a pipeline to exactly these.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(?RecruitmentPipeline $pipeline): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_default' => ['boolean'],

            'stages' => ['required', 'array', 'min:1', 'max:20'],
            'stages.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('recruitment_pipeline_stages', 'id')->where('recruitment_pipeline_id', $pipeline?->id ?? 0),
            ],
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
            'stages.*.id.exists' => 'A stage in the list is not one of this pipeline’s stages.',
        ];
    }

    /**
     * A pipeline needs exactly one "hired" stage and at least one "rejected"
     * stage — everything else business logic depends on (hiring, rejecting,
     * "what's next") assumes both exist.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => self::validateStages($validator, (array) $this->input('stages', [])));
    }

    /**
     * The checks {@see withValidator()} runs, for a caller with no request.
     *
     * @param  array<int, mixed>  $stages
     */
    public static function validateStages(Validator $validator, array $stages): void
    {
        $stages = collect($stages);

        if ($stages->where('kind', 'won')->count() !== 1) {
            $validator->errors()->add('stages', 'A pipeline needs exactly one "Hired" stage.');
        }

        if ($stages->where('kind', 'lost')->count() < 1) {
            $validator->errors()->add('stages', 'A pipeline needs at least one "Rejected" stage.');
        }
    }
}

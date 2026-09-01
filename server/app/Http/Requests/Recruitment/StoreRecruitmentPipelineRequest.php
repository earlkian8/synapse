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
 */
class StoreRecruitmentPipelineRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_default' => ['boolean'],

            'stages' => ['required', 'array', 'min:1', 'max:20'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.kind' => ['required', Rule::in(RecruitmentPipelineStage::KINDS)],
        ];
    }

    /**
     * A pipeline needs exactly one "hired" stage and at least one "rejected"
     * stage — everything else business logic depends on (hiring, rejecting,
     * "what's next") assumes both exist.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $stages = collect($this->input('stages', []));

            $won = $stages->where('kind', 'won')->count();
            $lost = $stages->where('kind', 'lost')->count();

            if ($won !== 1) {
                $validator->errors()->add('stages', 'A pipeline needs exactly one "Hired" stage.');
            }

            if ($lost < 1) {
                $validator->errors()->add('stages', 'A pipeline needs at least one "Rejected" stage.');
            }
        });
    }
}

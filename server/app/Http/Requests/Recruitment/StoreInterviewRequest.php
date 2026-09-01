<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterviewRequest extends FormRequest
{
    public const MODES = ['onsite', 'online', 'phone'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $application = $this->route('application');
        $pipelineId = $application?->jobPosting?->recruitment_pipeline_id;

        return [
            'interviewer_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'scheduled_at' => ['required', 'date'],
            'mode' => ['required', Rule::in(self::MODES)],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // The stage to move the candidate to once scheduled — defaults to
            // the pipeline's next open stage when left blank.
            'stage_id' => [
                'nullable',
                'integer',
                Rule::exists('recruitment_pipeline_stages', 'id')
                    ->where('recruitment_pipeline_id', $pipelineId)
                    ->where('kind', 'open'),
            ],
        ];
    }
}

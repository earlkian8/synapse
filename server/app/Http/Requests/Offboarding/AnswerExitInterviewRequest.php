<?php

namespace App\Http\Requests\Offboarding;

use App\Models\ExitInterview;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The leaver's answers to their exit interview (ADR 0076), held to the questions
 * their survey was sent with — not today's, should the questionnaire change.
 */
class AnswerExitInterviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ExitInterviewQuestionnaire::rules($this->questions());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ExitInterviewQuestionnaire::messages($this->questions());
    }

    /**
     * The signed-in person's survey, if one waits for them.
     */
    public function interview(): ?ExitInterview
    {
        $employee = $this->user()?->employee()->first();

        return $employee === null ? null : ExitInterview::query()->where('employee_id', $employee->id)->latest('id')->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questions(): array
    {
        return $this->interview()?->questions ?? ExitInterviewQuestionnaire::questions();
    }
}

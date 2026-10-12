<?php

namespace Database\Factories;

use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExitInterview>
 */
class ExitInterviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'offboarding_case_id' => OffboardingCase::factory(),
            'employee_id' => fn (array $attributes) => OffboardingCase::find($attributes['offboarding_case_id'])?->employee_id,
            'status' => 'pending',
            'questions' => ExitInterviewQuestionnaire::questions(),
            'answers' => null,
            'sent_at' => now(),
        ];
    }

    /**
     * Answered, with a plausible set of answers.
     */
    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => 'submitted',
            'submitted_at' => now(),
            'answers' => ExitInterviewQuestionnaire::sampleAnswers(),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\ResignationRequest;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResignationRequest>
 */
class ResignationRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(Tenancy::class)->id() ?? Organization::factory(),
            'employee_id' => Employee::factory(),
            'proposed_last_day' => now()->addDays(ResignationRequest::NOTICE_DAYS)->toDateString(),
            'reason' => fake()->sentence(),
            'status' => 'pending',
        ];
    }
}

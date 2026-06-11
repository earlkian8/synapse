<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\Department;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

class RecruitmentSeeder extends Seeder
{
    /**
     * Seed a starter recruitment pipeline for the current tenant: a few open
     * postings, an applicant pool, applications spread across the stages, and a
     * handful of scheduled interviews. Idempotent: only seeds when empty.
     */
    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        if (JobPosting::count() > 0) {
            return;
        }

        $poster = User::first();
        $departments = Department::with('positions')->get();

        if ($departments->isEmpty()) {
            return;
        }

        // 1. Job postings.
        $postings = collect(range(1, 4))->map(function () use ($departments, $poster) {
            $department = $departments->random();
            $position = $department->positions->isNotEmpty() ? $department->positions->random() : null;

            return JobPosting::factory()->create([
                'title' => $position?->title ?? 'Open Role',
                'department_id' => $department->id,
                'position_id' => $position?->id,
                'posted_by' => $poster?->id,
                'status' => 'open',
            ]);
        });

        // 2. Applicant pool.
        $applicants = Applicant::factory()->count(18)->create();

        // 3. Applications spread across the pipeline.
        $stages = ['applied', 'applied', 'screening', 'screening', 'interview', 'offer', 'rejected'];

        foreach ($postings as $posting) {
            $applicants->random(fake()->numberBetween(4, 8))->each(function (Applicant $applicant) use ($posting, $stages) {
                $stage = fake()->randomElement($stages);

                $application = JobApplication::factory()->create([
                    'job_posting_id' => $posting->id,
                    'applicant_id' => $applicant->id,
                    'stage' => $stage,
                    'rejected_reason' => $stage === 'rejected' ? 'Not a fit for the role' : null,
                    'decided_at' => $stage === 'rejected' ? now() : null,
                ]);

                // 4. Interviews for applications that reached the interview stage.
                if ($stage === 'interview') {
                    Interview::factory()->create([
                        'job_application_id' => $application->id,
                    ]);
                }
            });
        }
    }
}

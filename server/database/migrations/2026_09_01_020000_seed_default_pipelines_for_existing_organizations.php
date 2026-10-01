<?php

use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Every organisation that predates configurable pipelines gets a "Standard
     * Hiring" pipeline seeded with today's fixed 6-stage list, in the same order
     * and with the same open/won/lost semantics — so nothing about an existing
     * tenant's recruitment behaviour changes. New organisations created after this
     * migration start with zero pipelines (this codebase's established "no module
     * defaults, honest empty state" convention) and pick a pipeline for themselves
     * from Company Setup, or start from the same standard template in one click.
     *
     * Outside a request, {@see OrganizationScope} is a no-op
     * (see its docblock), so these Eloquent creates are safe here — same
     * established pattern as the `Organization::withTrashed()` back-fill in
     * 2026_08_10_000000_create_workspace_join_and_employee_invitations.php.
     */
    public function up(): void
    {
        $stages = [
            ['name' => 'Applied', 'kind' => 'open'],
            ['name' => 'Screening', 'kind' => 'open'],
            ['name' => 'Interview', 'kind' => 'open'],
            ['name' => 'Offer', 'kind' => 'open'],
            ['name' => 'Hired', 'kind' => 'won'],
            ['name' => 'Rejected', 'kind' => 'lost'],
        ];

        Organization::withTrashed()->get()->each(function (Organization $organization) use ($stages): void {
            $pipeline = RecruitmentPipeline::create([
                'organization_id' => $organization->id,
                'name' => 'Standard Hiring',
                'is_default' => true,
            ]);

            foreach ($stages as $position => $stage) {
                $pipeline->stages()->create([
                    'organization_id' => $organization->id,
                    'name' => $stage['name'],
                    'kind' => $stage['kind'],
                    'position' => $position,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Reversed by dropping the tables in the migration that created them.
    }
};

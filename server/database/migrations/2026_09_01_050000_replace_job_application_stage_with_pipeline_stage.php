<?php

use App\Models\JobApplication;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `job_applications.stage` (a free string) is replaced by a real foreign key to
     * the posting's pipeline's stages. Every existing application's posting was
     * just backfilled onto its organisation's seeded "Standard Hiring" pipeline
     * (the previous two migrations), so a case-insensitive name match against that
     * one pipeline's stages is sufficient — no other pipeline exists yet at this
     * point in the migration history.
     */
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->foreignId('recruitment_pipeline_stage_id')->nullable()->after('job_posting_id')
                ->constrained('recruitment_pipeline_stages')->restrictOnDelete();
            $table->json('screening_answers')->nullable()->after('rejected_reason'); // {question_id: bool}
        });

        Organization::withTrashed()->get()->each(function (Organization $organization): void {
            $pipeline = RecruitmentPipeline::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('is_default', true)
                ->with('stages')
                ->first();

            if ($pipeline === null) {
                return;
            }

            foreach ($pipeline->stages as $stage) {
                JobApplication::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->whereRaw('lower(stage) = ?', [mb_strtolower($stage->name)])
                    ->update(['recruitment_pipeline_stage_id' => $stage->id]);
            }
        });

        Schema::table('job_applications', function (Blueprint $table) {
            $table->foreignId('recruitment_pipeline_stage_id')->nullable(false)->change();
            $table->dropColumn('stage');
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->string('stage')->default('applied')->after('job_posting_id');
        });

        JobApplication::withoutGlobalScopes()->with('pipelineStage')->get()->each(
            fn (JobApplication $application) => $application
                ->forceFill(['stage' => mb_strtolower($application->pipelineStage->name)])
                ->save(),
        );

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recruitment_pipeline_stage_id');
            $table->dropColumn('screening_answers');
        });
    }
};

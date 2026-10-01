<?php

use App\Models\JobPosting;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A posting now points at a pipeline (its ordered stages), and can opt out of
     * requiring a résumé or running the automatic fit score — both default `true`
     * so existing postings keep behaving exactly as before.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->foreignId('recruitment_pipeline_id')->nullable()->after('organization_id')
                ->constrained()->restrictOnDelete();
            $table->boolean('requires_resume')->default(true)->after('skills');
            $table->boolean('use_fit_scoring')->default(true)->after('requires_resume');
        });

        Organization::withTrashed()->get()->each(function (Organization $organization): void {
            $default = RecruitmentPipeline::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('is_default', true)
                ->first();

            if ($default === null) {
                return;
            }

            JobPosting::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->whereNull('recruitment_pipeline_id')
                ->update(['recruitment_pipeline_id' => $default->id]);
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->foreignId('recruitment_pipeline_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recruitment_pipeline_id');
            $table->dropColumn(['requires_resume', 'use_fit_scoring']);
        });
    }
};

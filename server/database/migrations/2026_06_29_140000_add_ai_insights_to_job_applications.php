<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the latest LLM-generated candidate insight on the application, so the
 * detail drawer can show it instantly on reopen without spending another model
 * call. The whole insight payload (including its generated_at stamp) lives in one
 * JSON column; a fresh "Regenerate" overwrites it. See App\Support\Recruitment\ApplicantInsights.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->json('ai_insights')->nullable()->after('rejected_reason');
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn('ai_insights');
        });
    }
};

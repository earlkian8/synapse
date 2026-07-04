<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the on-demand LLM training-effectiveness read on the program itself, so
 * it reopens instantly and is regenerated only on request — mirroring the
 * `ai_insights` column on performance_evaluations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_programs', function (Blueprint $table) {
            $table->json('ai_insights')->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('training_programs', function (Blueprint $table) {
            $table->dropColumn('ai_insights');
        });
    }
};

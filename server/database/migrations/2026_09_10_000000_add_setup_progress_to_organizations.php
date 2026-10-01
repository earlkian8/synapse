<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a brand-new company is in its guided setup.
     *
     * Registration provisions an empty tenant (ADR 0005), and the modules that
     * read configuration — Leave, Recruitment, Performance — deliberately ship no
     * defaults. Until now the owner landed on an empty dashboard with no idea
     * which of the nine Company Setup screens had to be visited first. These two
     * columns are what the setup wizard resumes from:
     *
     *  - `setup_completed_at` — set once the owner reaches the end of the wizard
     *    or skips it outright. Null means "still show me the wizard".
     *  - `setup_steps` — a `step key => done|skipped` map, so returning to the
     *    wizard reopens at the first step that is neither.
     *
     * Every organisation that predates the wizard is already running, so it is
     * back-filled as complete — nobody is sent back through setup for a company
     * they have been using for months.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('setup_completed_at')->nullable()->after('join_code_enabled');
            $table->json('setup_steps')->nullable()->after('setup_completed_at');
        });

        // Query-builder, not Eloquent: back-filling progress is not an edit to the
        // company profile, so it must not move `updated_at` (which the profile
        // screen reads back as "Updated …").
        DB::table('organizations')->update(['setup_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['setup_completed_at', 'setup_steps']);
        });
    }
};

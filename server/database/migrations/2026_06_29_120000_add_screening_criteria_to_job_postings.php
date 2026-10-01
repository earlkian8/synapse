<?php

use App\Support\Recruitment\ApplicantScorer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Position-aware screening criteria for a posting.
     *
     * Two optional knobs let recruiters shape the automatic applicant ranking
     * (see {@see ApplicantScorer}): a minimum years of
     * experience and a list of required skill keywords. Both are nullable — the
     * scorer falls back to a sensible default rubric when a posting leaves them
     * blank, so ranking works with zero configuration.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_years_experience')->nullable()->after('requirements');
            $table->json('skills')->nullable()->after('min_years_experience'); // list<string> of required keywords
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['min_years_experience', 'skills']);
        });
    }
};

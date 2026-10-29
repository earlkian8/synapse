<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ATS reads what candidates send and matches skills by meaning
     * (ADR 0097).
     *
     *  - `applicants` keeps the text read out of the résumé (`resume_text`), how
     *    reading it went (`resume_read_status`: pending|read|unreadable, null
     *    without a résumé), and the text of the supporting documents together
     *    (`documents_text`), so the fit score never has to open a file.
     *  - `applicant_documents` keeps each document's own text and status; a
     *    government ID is `skipped` and never read.
     *  - `job_postings.preferred_skills` are the nice-to-haves. `skills` stays
     *    the must-haves, as it always meant.
     *
     * Résumés and documents already on file are marked `pending`, and
     * `recruitment:read-files` (hourly) reads them in batches.
     */
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->text('resume_text')->nullable()->after('resume');
            $table->string('resume_read_status', 12)->nullable()->after('resume_text');
            $table->text('documents_text')->nullable()->after('resume_read_status');
        });

        Schema::table('applicant_documents', function (Blueprint $table) {
            $table->text('extracted_text')->nullable()->after('file');
            $table->string('read_status', 12)->nullable()->after('extracted_text');
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->json('preferred_skills')->nullable()->after('skills');
        });

        DB::table('applicants')->whereNotNull('resume')->update(['resume_read_status' => 'pending']);
        DB::table('applicant_documents')->where('type', 'government_id')->update(['read_status' => 'skipped']);
        DB::table('applicant_documents')->where('type', '!=', 'government_id')->update(['read_status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('preferred_skills');
        });

        Schema::table('applicant_documents', function (Blueprint $table) {
            $table->dropColumn(['extracted_text', 'read_status']);
        });

        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn(['resume_text', 'resume_read_status', 'documents_text']);
        });
    }
};

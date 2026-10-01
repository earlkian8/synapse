<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrich the candidate record and let applicants attach supporting files.
     *
     * Two changes that make Recruitment usable as a public-facing application
     * channel (ADR 0006, public careers amendment):
     *  - `applicants` gains the profile fields a real candidate provides
     *    (location, professional links, years of experience). The single
     *    `resume` column stays — it is the canonical CV copied into the 201 file
     *    at hire.
     *  - a new `applicant_documents` table holds 0..N supporting uploads
     *    (cover letter, certificates, transcript, portfolio, government ID, …),
     *    mirroring `employee_documents`. Tenant-scoped; `uploaded_by` is null for
     *    public submissions.
     */
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('current_location')->nullable()->after('phone');
            $table->string('linkedin_url')->nullable()->after('headline');
            $table->string('portfolio_url')->nullable()->after('linkedin_url');
            $table->unsignedTinyInteger('years_experience')->nullable()->after('portfolio_url');
        });

        Schema::create('applicant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->default('other'); // cover_letter|certificate|transcript|portfolio|government_id|other
            $table->string('file');                    // stored on the public disk
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('applicant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_documents');

        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn([
                'current_location',
                'linkedin_url',
                'portfolio_url',
                'years_experience',
            ]);
        });
    }
};

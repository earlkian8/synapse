<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Problem reports (ADR 0096): somebody using the ERP or the app tells the
     * people who run SYNAPSE that something went wrong, and the platform admin
     * portal follows it up.
     *
     *  - `problem_reports` — one report. Not tenant-scoped: it is written to the
     *    platform, not to the company. `organization_id` records the workspace
     *    the reporter was in, for context, and stays when that workspace goes.
     *    `reference` is what the reporter and the admins call it (PR-7K2M9Q);
     *    ids are never shown, so nobody can count the platform's reports.
     *    The reporter's side: what kind, how much it gets in the way, a summary,
     *    what happened, and what the client sent with it (page, version,
     *    device, a few display facts in `context`). The admins' side: a status,
     *    a reply the reporter reads, a note only admins read, and who last
     *    handled it.
     *  - `problem_report_attachments` — up to three screenshots, on the private
     *    uploads disk (ADR 0087), re-encoded on the way in so no photo keeps its
     *    location or camera data.
     */
    public function up(): void
    {
        Schema::create('problem_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // not_working|looks_wrong|slow|other
            $table->string('category', 20);
            // blocked|workaround|minor
            $table->string('impact', 20);
            $table->string('subject', 120);
            $table->text('description');
            // web|ios|android
            $table->string('platform', 10);
            $table->string('page')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('device')->nullable();
            $table->json('context')->nullable();
            // new|investigating|resolved|closed
            $table->string('status', 20)->default('new');
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('problem_report_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_report_id')->constrained()->cascadeOnDelete();
            $table->string('path')->unique();
            $table->string('name');
            $table->string('mime', 50);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_report_attachments');
        Schema::dropIfExists('problem_reports');
    }
};

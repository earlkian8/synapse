<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the ATS has learned from ESCO about the skills its own catalogue
     * doesn't know (ADR 0100), so the fit score reads it from the database and
     * never waits on the network.
     *
     *  - `esco_skills` holds each ESCO skill once: its preferred label, its other
     *    labels, and the skills just broader and narrower than it.
     *  - `esco_skill_terms` holds each skill a posting asked for (folded, as
     *    `Words::fold` folds it) and what ESCO said: `matched` (with the skill),
     *    `none` (ESCO has no skill by that name) or `failed` (ESCO couldn't be
     *    reached; tried again).
     *
     * Both are reference data, the same for every workspace, so neither has an
     * `organization_id`: a term is a skill's name, never anything about who
     * asked for it.
     */
    public function up(): void
    {
        Schema::create('esco_skills', function (Blueprint $table) {
            $table->id();
            $table->string('uri')->unique();
            $table->string('label');
            $table->json('alt_labels')->nullable();
            $table->text('description')->nullable();
            // skill | knowledge, as ESCO types it.
            $table->string('skill_type', 20)->nullable();
            // [{uri, label}] — the skills just broader and just narrower.
            $table->json('broader')->nullable();
            $table->json('narrower')->nullable();
            $table->timestamps();
        });

        Schema::create('esco_skill_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 120)->unique();
            $table->foreignId('esco_skill_id')->nullable()->constrained('esco_skills')->nullOnDelete();
            $table->string('status', 10);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('looked_up_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esco_skill_terms');
        Schema::dropIfExists('esco_skills');
    }
};

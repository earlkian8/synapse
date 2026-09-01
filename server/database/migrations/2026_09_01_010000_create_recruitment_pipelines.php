<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pipeline is a named, ordered set of hiring stages a tenant defines for
     * itself — the generic replacement for the old hardcoded 6-stage list. A
     * posting picks one pipeline; a stage's `kind` (open/won/lost) is what business
     * logic keys off, never its name, so a stage can be called anything ("Physical
     * Assessment", "Trial Shift") and still behave correctly.
     */
    public function up(): void
    {
        Schema::create('recruitment_pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'is_default']);
        });

        Schema::create('recruitment_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recruitment_pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind')->default('open'); // open|won|lost
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['recruitment_pipeline_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_pipeline_stages');
        Schema::dropIfExists('recruitment_pipelines');
    }
};

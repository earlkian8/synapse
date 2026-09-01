<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A posting's own yes/no screening questions ("Valid driver's license?",
     * "Available for night shift?") — the generic complement to the existing
     * min-years-experience/skills criteria, for anything those two don't cover.
     */
    public function up(): void
    {
        Schema::create('job_posting_screening_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['job_posting_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_posting_screening_questions');
    }
};

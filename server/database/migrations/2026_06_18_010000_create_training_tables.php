<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Training & Development (ERD §8). Two tenant-scoped tables:
     *
     *  - **training_programs** — a scheduled training program / cohort the
     *    organisation runs (provider, date window, seat capacity). The program's
     *    status (upcoming / ongoing / completed) is **derived from its dates**, not
     *    stored, so it can never drift.
     *  - **training_enrollments** — which employees are taking which program, with a
     *    status (enrolled / completed / dropped), an optional completion score and
     *    timestamp, and optional remarks.
     *
     * Created in-module (there is no Company-Setup config for training, unlike
     * benefits/payroll). `end_date` and `capacity` are nullable — an open-ended or
     * uncapped program is allowed.
     */
    public function up(): void
    {
        Schema::create('training_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('provider')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Seat capacity; null = uncapped.
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('start_date');
        });

        Schema::create('training_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // enrolled|completed|dropped
            $table->string('status')->default('enrolled');
            // Completion score (0–100); null until graded.
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            // One enrollment per employee per program.
            $table->unique(['training_program_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_enrollments');
        Schema::dropIfExists('training_programs');
    }
};

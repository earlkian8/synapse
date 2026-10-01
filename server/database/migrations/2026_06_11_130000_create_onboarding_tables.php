<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Onboarding module — the bridge between a hire and a productive employee.
     * Reusable **programs** (templates) define a checklist of blueprint tasks; a
     * **case** is one employee's onboarding, instantiated from a program (usually at
     * hire time), holding the concrete **tasks** that HR and stakeholders complete.
     * Every table is tenant-scoped (`organization_id`). See ADR 0007.
     */
    public function up(): void
    {
        // Reusable onboarding templates, optionally targeted at a department / type.
        Schema::create('onboarding_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employment_type')->nullable(); // regular|probationary|contractual|part_time
            $table->boolean('is_default')->default(false);  // fallback when nothing else matches
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // Blueprint tasks belonging to a program.
        Schema::create('onboarding_program_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('onboarding_program_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('other'); // paperwork|equipment|access|orientation|training|compliance|other
            $table->unsignedSmallInteger('due_offset_days')->default(7); // days after start the task is due
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // One employee's onboarding journey.
        Schema::create('onboarding_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('onboarding_program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending|in_progress|completed|cancelled
            $table->date('start_date');
            $table->date('target_end_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // An employee onboards once.
            $table->unique('employee_id');
            $table->index('status');
        });

        // Concrete checklist tasks for a case.
        Schema::create('onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('onboarding_case_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('other');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status')->default('pending'); // pending|in_progress|done|skipped
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('status');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('onboarding_cases');
        Schema::dropIfExists('onboarding_program_tasks');
        Schema::dropIfExists('onboarding_programs');
    }
};

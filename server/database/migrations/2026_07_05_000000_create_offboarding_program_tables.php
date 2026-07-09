<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Offboarding **programs** — reusable clearance templates, the exit-side mirror
     * of onboarding programs. A program defines the sign-off items an exit starts
     * with, optionally targeted at a department and/or exit type; starting an
     * offboarding instantiates the best-matching (or explicitly chosen) active
     * program into the case's clearance checklist, replacing the hardcoded standard
     * list. Every table is tenant-scoped (`organization_id`).
     */
    public function up(): void
    {
        // Reusable clearance templates, optionally targeted at a department / exit type.
        Schema::create('offboarding_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // The employees this template targets (their department), not the owner.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('exit_type')->nullable(); // resignation|termination|retirement|end_of_contract
            $table->boolean('is_default')->default(false);  // fallback when nothing else matches
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // Blueprint sign-offs belonging to a program.
        Schema::create('offboarding_program_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_program_id')->constrained()->cascadeOnDelete();
            $table->string('item');
            // The department responsible for the sign-off (e.g. IT, Finance)…
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            // …or the departing employee's own department, resolved at instantiation.
            $table->boolean('use_employee_department')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Trace which template seeded a case (null = ad-hoc / standard checklist).
        Schema::table('offboarding_cases', function (Blueprint $table) {
            $table->foreignId('offboarding_program_id')
                ->nullable()
                ->after('employee_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offboarding_program_id');
        });

        Schema::dropIfExists('offboarding_program_items');
        Schema::dropIfExists('offboarding_programs');
    }
};

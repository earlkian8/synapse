<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Offboarding module (ERD §9) — the structured exit of a departing
     * employee. Mirrors Onboarding's shape (a parent **case** + a child
     * checklist), but the checklist is a **clearance** grouped by the responsible
     * department rather than a task list grouped by category.
     *
     *  - **offboarding_cases** — one employee's exit: the exit kind, the notice /
     *    last-working-day dates, the reason, and a deliberate lifecycle
     *    (initiated → clearance → completed, or cancelled). Each employee exits
     *    once (`unique(employee_id)`). The *clearance status* is **derived** from
     *    the items, never stored, so it cannot drift.
     *  - **clearance_items** — the sign-offs the exit requires (return assets,
     *    settle accountabilities, knowledge handover…), each owned by a department
     *    and signed off (cleared) or raised (flagged) by a user.
     *
     * Every table is tenant-scoped (`organization_id`). See ADR 0016.
     */
    public function up(): void
    {
        // One employee's exit journey.
        Schema::create('offboarding_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // resignation|termination|retirement|end_of_contract
            $table->string('type')->default('resignation');
            $table->date('notice_date')->nullable();        // when the employee gave / was given notice
            $table->date('last_working_day')->nullable();   // effective separation date
            $table->text('reason')->nullable();
            // initiated|clearance|completed|cancelled (deliberate lifecycle)
            $table->string('status')->default('initiated');
            $table->timestamp('completed_at')->nullable();  // stamped when the exit is finalised
            $table->timestamps();

            // An employee offboards once.
            $table->unique('employee_id');
            $table->index('status');
            $table->index('last_working_day');
        });

        // The clearance sign-offs for a case.
        Schema::create('clearance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_case_id')->constrained()->cascadeOnDelete();
            $table->string('item');
            // The department responsible for signing this off (e.g. IT, Finance).
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            // pending|cleared|flagged (flagged = an outstanding issue blocks the exit)
            $table->string('status')->default('pending');
            $table->text('remarks')->nullable();            // sign-off note / why it was flagged
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clearance_items');
        Schema::dropIfExists('offboarding_cases');
    }
};

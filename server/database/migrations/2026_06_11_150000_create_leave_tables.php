<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave Management — time off, the half of HR operations that sits beside
     * Attendance.
     *
     *  - **leave_types**  (Company Setup): the kinds of leave an org grants
     *    (Vacation, Sick, …) with their default annual entitlement and policy
     *    flags. A configuration lookup, archived rather than hard-deleted.
     *  - **leave_balances**: a per-employee, per-type, per-year **allocation**
     *    (entitlement). Used / pending days are derived from requests, not stored,
     *    so they can never drift.
     *  - **leave_requests**: an employee's filed time off, with an approval
     *    lifecycle (pending → approved / rejected, plus cancelled).
     *
     * Every table is tenant-scoped (`organization_id`). See ADR 0009.
     */
    public function up(): void
    {
        // Kinds of leave (configured under Company Setup).
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code'); // e.g. VL, SL — unique per tenant (partial index below)
            $table->text('description')->nullable();
            $table->string('color', 20)->default('#0ABFBF'); // badge / calendar accent
            $table->decimal('default_days', 5, 1)->default(0); // annual entitlement
            $table->boolean('is_paid')->default(true);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('requires_approval')->default(true); // false → auto-approved on file
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        // `code` is unique per tenant, ignoring archived rows (so archiving frees it).
        DB::statement('CREATE UNIQUE INDEX leave_types_org_code_unique ON leave_types (organization_id, code) WHERE deleted_at IS NULL');

        // A year's entitlement allocation for one employee + leave type.
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled_days', 5, 1)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            // One allocation per employee / type / year.
            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        // A filed leave request and its approval lifecycle.
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Restrict on delete: a type with history can't be hard-deleted (it is archived instead).
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 4, 1)->default(0); // working days, server-computed (supports 0.5)
            $table->boolean('is_half_day')->default(false);
            $table->string('half_day_period')->nullable(); // morning|afternoon (single-day only)
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // pending|approved|rejected|cancelled
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('start_date');
            $table->index(['employee_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        DB::statement('DROP INDEX IF EXISTS leave_types_org_code_unique');
        Schema::dropIfExists('leave_types');
    }
};

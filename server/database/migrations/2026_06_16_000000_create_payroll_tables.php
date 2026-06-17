<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payroll & Benefits (ERD §7), plus the two Company-Setup config tables it
     * reads from (ERD §2: allowance_types, deduction_types). The operational
     * tables:
     *
     *  - **payroll_periods** — one pay run (start/end + pay date) with a status
     *    lifecycle (draft → processing → finalized → paid).
     *  - **payslips** — one employee's computed pay for a period: basic / overtime
     *    / gross / earnings / deductions / net, with the days worked. Totals are
     *    derived server-side (see App\Support\Payroll), never trusted from the
     *    client; one payslip per employee per period.
     *  - **payslip_earnings / payslip_deductions** — the itemised lines a payslip
     *    is built from, each optionally typed by an allowance_type / deduction_type.
     *
     * Money is decimal(12,2); everything is tenant-scoped (organization_id).
     */
    public function up(): void
    {
        // ── Company Setup config (referenced by the payslip lines) ──────────────
        Schema::create('allowance_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_taxable')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('deduction_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // sss|philhealth|pagibig|withholding_tax|loan|other
            $table->string('kind')->default('other');
            $table->boolean('is_mandatory')->default(false);
            // Rate / bracket config the calculator interprets (e.g. {"type":"rate"…}).
            $table->json('computation')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Payroll runs ────────────────────────────────────────────────────────
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('pay_date');
            // draft|processing|finalized|paid
            $table->string('status')->default('draft');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('start_date');
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->decimal('basic_pay', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->decimal('total_earnings', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->decimal('days_worked', 6, 2)->default(0);

            // draft|released
            $table->string('status')->default('draft');

            $table->timestamps();

            // One payslip per employee per period.
            $table->unique(['payroll_period_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::create('payslip_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allowance_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payslip_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payslip_deductions');
        Schema::dropIfExists('payslip_earnings');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('deduction_types');
        Schema::dropIfExists('allowance_types');
    }
};

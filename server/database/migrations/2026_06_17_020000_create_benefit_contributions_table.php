<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statutory benefit contributions (ERD §7 `BENEFIT_CONTRIBUTION`): one row per
     * employee per pay period per government benefit (SSS / PhilHealth / Pag-IBIG),
     * holding the **employee** and **employer** shares + total. Generated from each
     * processed payroll run's statutory deductions — the employer share, which the
     * payslip deductions don't carry, is the gap this fills (it's a company cost,
     * remitted together with the employee's). Drives the monthly remittance report.
     *
     * Tenant-scoped (organization_id); money is decimal(12,2).
     */
    public function up(): void
    {
        Schema::create('benefit_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // The run this was derived from (null if recorded outside a run).
            $table->foreignId('payroll_period_id')->nullable()->constrained()->cascadeOnDelete();
            // The remittance month, "YYYY-MM" (from the run's end date).
            $table->string('period', 7);
            // sss|philhealth|pagibig
            $table->string('benefit');
            $table->decimal('employee_share', 12, 2)->default(0);
            $table->decimal('employer_share', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            // One contribution per employee per benefit per run.
            $table->unique(['payroll_period_id', 'employee_id', 'benefit']);
            $table->index(['period', 'benefit']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_contributions');
    }
};

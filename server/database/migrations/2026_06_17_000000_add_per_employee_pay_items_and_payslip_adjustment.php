<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-employee recurring pay items + manual payslip adjustment.
     *
     *  - **employee_allowances** (ERD §3 EMPLOYEE_ALLOWANCE, finally built) — ties
     *    an allowance_type to an individual employee with a per-employee peso
     *    amount, so Company-Setup allowance config actually drives payslips.
     *  - **employee_deductions** (ERD §3/§7 extension) — the symmetric table for
     *    recurring per-employee deductions (e.g. a loan), typed by a deduction_type.
     *  - **payslips.is_adjusted** — flags a payslip whose lines were hand-edited so
     *    a re-process leaves it untouched (see App\Support\Payroll\PayrollProcessor).
     *
     * Money is decimal(12,2); both new tables are tenant-scoped (organization_id).
     */
    public function up(): void
    {
        Schema::create('employee_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allowance_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('employee_id');
        });

        Schema::create('employee_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('employee_id');
        });

        Schema::table('payslips', function (Blueprint $table) {
            // Set once a payslip's lines are hand-edited; a re-process skips it.
            $table->boolean('is_adjusted')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('is_adjusted');
        });

        Schema::dropIfExists('employee_deductions');
        Schema::dropIfExists('employee_allowances');
    }
};

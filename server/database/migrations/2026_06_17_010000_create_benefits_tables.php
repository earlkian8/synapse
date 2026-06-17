<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Benefits Administration (ERD §7, the benefits side). Two tenant-scoped tables:
     *
     *  - **benefit_plans** — the Company-Setup catalogue of benefit plans the
     *    organisation offers (HMO, life insurance, retirement, wellness…), each with
     *    a provider, the employee / employer share per period, and a billing
     *    frequency. The configuration layer the Benefits module reads.
     *  - **benefit_enrollments** — which employees are enrolled in which plan, with
     *    a status (active / pending / waived / terminated), an optional member /
     *    policy reference, and the coverage dates.
     *
     * Money is decimal(12,2). Replaces the deferred `benefit_contributions` snapshot
     * with a richer plan + enrollment model.
     */
    public function up(): void
    {
        Schema::create('benefit_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // hmo|insurance|retirement|wellness|other
            $table->string('category')->default('other');
            $table->string('provider')->nullable();
            $table->text('description')->nullable();
            $table->decimal('employee_cost', 12, 2)->default(0);
            $table->decimal('employer_cost', 12, 2)->default(0);
            // monthly|quarterly|annual|one_time
            $table->string('frequency')->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
        });

        Schema::create('benefit_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('benefit_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // active|pending|waived|terminated
            $table->string('status')->default('active');
            $table->string('reference_no')->nullable();
            $table->date('enrolled_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One enrollment per employee per plan.
            $table->unique(['benefit_plan_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_enrollments');
        Schema::dropIfExists('benefit_plans');
    }
};

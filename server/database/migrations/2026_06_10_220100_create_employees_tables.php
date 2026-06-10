<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Employee core — the hub almost every operational module references —
     * plus its directly-owned sub-records (documents, certifications, promotion
     * history). An Employee is the HR record; `user_id` optionally links it to an
     * auth account (see ADR 0004).
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('employee_no')->unique();

            // Identity
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();        // male|female|other
            $table->string('civil_status')->nullable();  // single|married|widowed|separated|divorced
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('photo')->nullable();

            // Placement
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('work_schedule_id')->nullable()->constrained()->nullOnDelete();

            // Employment
            $table->string('employment_type')->default('probationary'); // regular|probationary|contractual|part_time
            $table->string('employment_status')->default('active');     // active|on_leave|suspended|resigned|terminated
            $table->date('date_hired');
            $table->date('date_regularized')->nullable();

            // Compensation
            $table->decimal('basic_salary', 12, 2)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no')->nullable();

            // Government IDs
            $table->string('tin')->nullable();
            $table->string('sss_no')->nullable();
            $table->string('philhealth_no')->nullable();
            $table->string('pagibig_no')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('employment_status');
            $table->index('employment_type');
        });

        // Now that employees exists, wire the department head FK.
        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('head_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->default('other'); // contract|cv|govt_id|other
            $table->string('file');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employee_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('issuer')->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('file')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->decimal('from_salary', 12, 2)->nullable();
            $table->decimal('to_salary', 12, 2)->nullable();
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['head_id']);
        });

        Schema::dropIfExists('employee_promotions');
        Schema::dropIfExists('employee_certifications');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employees');
    }
};

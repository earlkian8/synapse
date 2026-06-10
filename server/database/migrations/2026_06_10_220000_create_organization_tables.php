<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The organisation/configuration layer the Employee module references:
     * departments (org chart), positions (job titles + salary band) and work
     * schedules. `departments.head_id` points at an employee, so its FK is added
     * later in the employees migration (once that table exists).
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedBigInteger('head_id')->nullable(); // -> employees.id (FK added later)
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('head_id');
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('salary_grade_min', 12, 2)->nullable();
            $table->decimal('salary_grade_max', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->json('work_days')->nullable(); // ["Mon","Tue",...]
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->decimal('required_hours', 5, 2)->default(8);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }
};

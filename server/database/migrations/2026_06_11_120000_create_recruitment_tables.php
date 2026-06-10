<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Recruitment module — an applicant tracking system (ATS). Job postings
     * collect applications from a shared applicant pool; each application advances
     * through a hiring pipeline and may schedule interviews. A hired application
     * links to the Employee it produced (the recruitment → workforce bridge). Every
     * table is tenant-scoped (`organization_id`).
     */
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->string('employment_type')->default('regular'); // regular|probationary|contractual|part_time
            $table->unsignedSmallInteger('openings')->default(1);
            $table->string('status')->default('draft');             // draft|open|closed|filled
            $table->date('closing_date')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('headline')->nullable();                 // current role / one-liner
            $table->string('source')->default('other');             // website|referral|linkedin|agency|walk_in|other
            $table->string('resume')->nullable();                   // stored on the public disk
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('email');
        });

        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('stage')->default('applied');            // applied|screening|interview|offer|hired|rejected
            $table->unsignedTinyInteger('rating')->nullable();      // 1..5
            $table->decimal('expected_salary', 12, 2)->nullable();
            $table->text('cover_note')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('hired_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // A candidate applies to a given posting at most once.
            $table->unique(['job_posting_id', 'applicant_id']);
            $table->index('stage');
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('mode')->default('onsite');              // onsite|online|phone
            $table->string('location')->nullable();                 // venue or meeting link
            $table->text('notes')->nullable();
            $table->string('result')->default('pending');           // pending|passed|failed
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->index('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('job_postings');
    }
};

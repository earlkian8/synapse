<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Job postings say what a role pays and where it is done, and interviews can
     * be conducted by people outside HR (ADR 0091).
     *
     *  - `job_postings` gains a pay range with the period it is quoted in, whether
     *    the careers page shows it, whether applicants are asked their expected
     *    pay, the work arrangement and the site the role is based at.
     *  - Years of experience take a decimal ("1.5 years"), on the posting's
     *    minimum and on an applicant's stated experience.
     *  - An application's expected salary records the period it was given in.
     *    Older answers have none: they were asked without one.
     *  - `recruitment.interview` is new, and the built-in Department Head and
     *    Staff roles of every company are given it: it reaches only the
     *    interviews a person is assigned.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->decimal('salary_min', 12, 2)->nullable()->after('openings');
            $table->decimal('salary_max', 12, 2)->nullable()->after('salary_min');
            $table->string('salary_period', 10)->default('monthly')->after('salary_max');
            $table->boolean('show_salary')->default(true)->after('salary_period');
            $table->boolean('asks_expected_salary')->default(true)->after('requires_resume');
            $table->string('work_arrangement', 10)->default('onsite')->after('employment_type');
            $table->foreignId('work_location_id')->nullable()->after('work_arrangement')->constrained()->nullOnDelete();
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->decimal('min_years_experience', 4, 1)->nullable()->change();
        });

        Schema::table('applicants', function (Blueprint $table) {
            $table->decimal('years_experience', 4, 1)->nullable()->change();
        });

        Schema::table('job_applications', function (Blueprint $table) {
            $table->string('expected_salary_period', 10)->nullable()->after('expected_salary');
        });

        PermissionSyncer::grant(['recruitment.interview'], [Role::DEPARTMENT_HEAD, Role::STAFF]);
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn('expected_salary_period');
        });

        Schema::table('applicants', function (Blueprint $table) {
            $table->unsignedTinyInteger('years_experience')->nullable()->change();
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_years_experience')->nullable()->change();
            $table->dropConstrainedForeignId('work_location_id');
            $table->dropColumn(['salary_min', 'salary_max', 'salary_period', 'show_salary', 'asks_expected_salary', 'work_arrangement']);
        });
    }
};

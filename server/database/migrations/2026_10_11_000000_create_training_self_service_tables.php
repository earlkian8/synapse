<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Training by everybody (ADRs 0074, 0075): employees join programs
     * themselves, a program runs as dated sessions with attendance, completing
     * it can earn a certificate that expires, and what training costs is
     * recorded against a budget.
     *
     *  - `training_programs`: who may join (`enrollment_mode`: closed, open,
     *    approval), whether completing it earns a certificate and for how many
     *    months it is valid, and the program's budget.
     *  - `training_enrollments`: a request to join is an enrollment row too
     *    (`requested`, then `enrolled` or `declined`), with who decided it,
     *    when, and why.
     *  - **training_sessions** — one dated sitting of a program, on the
     *    organisation's wall clock (a date and local start / end times).
     *  - **training_attendances** — one enrollee at one session: present, late,
     *    absent or excused.
     *  - **training_costs** — what was spent on a program: a category, an amount
     *    and the date it was incurred.
     *  - **training_budgets** — the organisation's training budget for a year.
     *  - `employee_certifications`: a certificate earned by completing a program
     *    is an ordinary certification, tied to the enrollment that earned it, with
     *    a certificate number and when its holder was told it was expiring.
     */
    public function up(): void
    {
        Schema::table('training_programs', function (Blueprint $table) {
            // closed|open|approval — who may put themselves on the roster.
            $table->string('enrollment_mode', 16)->default('closed')->after('capacity');
            $table->boolean('issues_certificate')->default(false)->after('enrollment_mode');
            // Months a certificate stays valid; null = it never expires.
            $table->unsignedSmallInteger('certificate_validity_months')->nullable()->after('issues_certificate');
            $table->decimal('budget', 12, 2)->nullable()->after('certificate_validity_months');
        });

        Schema::table('training_enrollments', function (Blueprint $table) {
            // Who approved or declined a request to join, when and why.
            $table->foreignId('decided_by')->nullable()->after('remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->text('decision_note')->nullable()->after('decided_at');

            $table->index(['training_program_id', 'status']);
        });

        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_program_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            // The organisation's wall clock (ADR 0036): a day and local times.
            $table->date('session_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['training_program_id', 'session_date']);
            $table->index(['organization_id', 'session_date']);
        });

        Schema::create('training_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_enrollment_id')->constrained()->cascadeOnDelete();
            // present|late|absent|excused
            $table->string('status', 16);
            $table->string('note', 500)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['training_session_id', 'training_enrollment_id']);
            $table->index('training_enrollment_id');
        });

        Schema::create('training_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_program_id')->constrained()->cascadeOnDelete();
            // trainer_fee|venue|materials|travel|meals|certification|other
            $table->string('category', 24);
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('incurred_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['training_program_id', 'incurred_on']);
            $table->index(['organization_id', 'incurred_on']);
        });

        Schema::create('training_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'year']);
        });

        Schema::table('employee_certifications', function (Blueprint $table) {
            // Set when the certification was earned by completing a program; it
            // goes when that completion is undone or the enrollment removed.
            $table->foreignId('training_enrollment_id')->nullable()->after('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('certificate_no', 40)->nullable()->after('issuer');
            $table->timestamp('expiry_notified_at')->nullable()->after('expiry_date');
        });

        PermissionSyncer::grant(['training.participate'], [Role::STAFF, Role::DEPARTMENT_HEAD, Role::HR_MANAGER]);
    }

    public function down(): void
    {
        Schema::table('employee_certifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('training_enrollment_id');
            $table->dropColumn(['certificate_no', 'expiry_notified_at']);
        });

        Schema::dropIfExists('training_budgets');
        Schema::dropIfExists('training_costs');
        Schema::dropIfExists('training_attendances');
        Schema::dropIfExists('training_sessions');

        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->dropIndex(['training_program_id', 'status']);
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['decided_at', 'decision_note']);
        });

        Schema::table('training_programs', function (Blueprint $table) {
            $table->dropColumn(['enrollment_mode', 'issues_certificate', 'certificate_validity_months', 'budget']);
        });

        // The permission row (and its role grants, by cascade) goes with the
        // registry entry on the next sync.
    }
};

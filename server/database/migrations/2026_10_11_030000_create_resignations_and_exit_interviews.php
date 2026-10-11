<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exits that the employee starts, hears about and leaves with paper for
     * (ADRs 0076, 0077).
     *
     *  - `resignation_requests` — a resignation the employee filed: the last day
     *    they propose and why, waiting for HR (`pending`), then `accepted` (it
     *    became the exit in `offboarding_case_id`), `declined` with a note, or
     *    `withdrawn` by them.
     *  - `exit_interviews` — one per exit: the survey sent to the leaver, its
     *    questions as they were asked (a snapshot), their answers, and when it
     *    was sent, reminded, answered or waived.
     *  - `offboarding_cases.certificate_no` / `certificate_issued_at` — the
     *    Certificate of Employment's number, given the first time it is issued.
     *  - `organizations.document_signatory_name` / `_title` — who signs the
     *    documents the system generates.
     *
     * `offboarding.resign` is the self-service permission; every built-in role
     * that is somebody's job gets it.
     */
    public function up(): void
    {
        Schema::create('resignation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Who filed it — the employee's own account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('proposed_last_day');
            $table->text('reason')->nullable();
            // pending|accepted|declined|withdrawn
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('offboarding_case_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_case_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // pending|submitted|waived
            $table->string('status', 16)->default('pending');
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::table('offboarding_cases', function (Blueprint $table) {
            $table->string('certificate_no', 40)->nullable()->after('completed_at');
            $table->timestamp('certificate_issued_at')->nullable()->after('certificate_no');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('document_signatory_name', 150)->nullable();
            $table->string('document_signatory_title', 150)->nullable();
        });

        PermissionSyncer::grant(['offboarding.resign'], [Role::STAFF, Role::DEPARTMENT_HEAD, Role::HR_MANAGER]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['document_signatory_name', 'document_signatory_title']);
        });

        Schema::table('offboarding_cases', function (Blueprint $table) {
            $table->dropColumn(['certificate_no', 'certificate_issued_at']);
        });

        Schema::dropIfExists('exit_interviews');
        Schema::dropIfExists('resignation_requests');

        // The permission row (and its role grants, by cascade) goes with the
        // registry entry on the next sync.
    }
};

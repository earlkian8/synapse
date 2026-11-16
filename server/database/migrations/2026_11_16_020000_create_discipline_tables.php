<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discipline (ADR 0105): incidents, the twin-notice due process of the Labor
     * Code and DOLE Department Order No. 147-15, a code of conduct with
     * progressive penalties, and each employee's conduct record.
     *
     *  - `conduct_offenses`: the company's code of conduct — each offense, how
     *    grave, the Labor Code ground it rests on, the penalty for each
     *    repetition, and how long an offense counts towards the next.
     *  - `disciplinary_cases`: one incident about one employee, from the report
     *    through the notice to explain, their explanation, a hearing when one is
     *    held, to the notice of decision — each step dated, with the employee's
     *    acknowledgement of each notice as proof it was served.
     *  - `disciplinary_case_attachments`: the evidence, private to HR.
     *  - `discipline.view|manage` go to every HR Manager, `discipline.report` to
     *    Department Heads too, `discipline.respond` (one's own notices) to all.
     */
    public function up(): void
    {
        Schema::create('conduct_offenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('gravity', 16)->default('minor');
            $table->string('ground', 32)->default('company_rule');
            $table->json('penalties');
            $table->unsignedSmallInteger('reckoning_months')->default(12);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'deleted_at']);
        });

        Schema::create('disciplinary_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 24);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conduct_offense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('reported');

            // The incident, as reported.
            $table->date('incident_date');
            $table->string('incident_time', 5)->nullable();
            $table->string('location', 160)->nullable();
            $table->text('narrative');
            $table->text('witnesses')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();

            // The first notice: the charge, and the time to answer it.
            $table->text('notice_grounds')->nullable();
            $table->timestamp('notice_issued_at')->nullable();
            $table->foreignId('notice_issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reply_due_on')->nullable();
            $table->timestamp('notice_received_at')->nullable();

            // Preventive suspension, at most 30 days.
            $table->date('preventive_from')->nullable();
            $table->date('preventive_to')->nullable();

            // The employee's answer.
            $table->text('explanation')->nullable();
            $table->timestamp('explained_at')->nullable();
            $table->foreignId('explanation_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('hearing_requested')->default(false);

            // A hearing or conference, when one is held.
            $table->timestamp('hearing_at')->nullable();
            $table->string('hearing_place', 160)->nullable();
            $table->timestamp('hearing_held_at')->nullable();
            $table->text('hearing_notes')->nullable();
            $table->boolean('hearing_attended')->nullable();

            // The second notice: the decision.
            $table->string('finding', 12)->nullable();
            $table->string('penalty', 20)->nullable();
            $table->string('suggested_penalty', 20)->nullable();
            $table->unsignedSmallInteger('occurrence')->nullable();
            $table->unsignedSmallInteger('suspension_days')->nullable();
            $table->date('penalty_from')->nullable();
            $table->date('penalty_to')->nullable();
            $table->text('decision')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decision_received_at')->nullable();

            $table->text('withdrawn_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
            $table->index('employee_id');
        });

        Schema::create('disciplinary_case_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disciplinary_case_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name', 200);
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('organization_id');
        });

        PermissionSyncer::grant(['discipline.view', 'discipline.manage', 'discipline.report', 'discipline.respond'], [Role::HR_MANAGER]);
        PermissionSyncer::grant(['discipline.report', 'discipline.respond'], [Role::DEPARTMENT_HEAD]);
        PermissionSyncer::grant(['discipline.respond'], [Role::STAFF]);
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_case_attachments');
        Schema::dropIfExists('disciplinary_cases');
        Schema::dropIfExists('conduct_offenses');
    }
};

<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performance by everybody (ADRs 0072, 0073): the people around an appraisal
     * review it, the employee acknowledges it themselves, goals are checked in on
     * through the cycle, and a calibration session compares and moves ratings.
     *
     *  - `performance_evaluations`: when the result was **shared** with the
     *    employee (a calibration session can hold it back), who acknowledged it
     *    and their comment, and — when calibration moved the rating — what the
     *    scorecard itself gave (`scored_band` / `scored_label`).
     *  - **appraisal_reviews** — one person's review of an appraisal: self,
     *    manager, peer or direct report (derived from the reporting line), its
     *    status, and the written answers.
     *  - **appraisal_review_scores** — that review's rating on one criterion of
     *    the appraisal, read on the line's own frozen scale.
     *  - **goal_templates** — the goal library (Company Setup): wording and a
     *    target to start a goal from.
     *  - **performance_goals** — what one employee commits to in a cycle, its
     *    target, where it stands and how it is going.
     *  - **goal_check_ins** — each update on a goal: the value then, how it is
     *    going and a note. Append-only.
     *  - **calibration_sessions** — a meeting over one cycle (or some of its
     *    departments) that compares and moves submitted ratings; its
     *    **calibration_participants**; and **calibration_adjustments**, every
     *    move with its reason.
     */
    public function up(): void
    {
        Schema::table('performance_evaluations', function (Blueprint $table) {
            $table->timestamp('shared_at')->nullable()->after('submitted_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->text('employee_comment')->nullable()->after('acknowledged_by');
            $table->string('scored_band')->nullable()->after('result_label');
            $table->string('scored_label')->nullable()->after('scored_band');
            $table->timestamp('calibrated_at')->nullable()->after('scored_label');
        });

        // Everything already submitted was visible to HR at submission; there was
        // no holding back, so that is when it was shared.
        DB::table('performance_evaluations')
            ->whereIn('status', ['submitted', 'acknowledged'])
            ->update(['shared_at' => DB::raw('coalesce(submitted_at, updated_at)')]);

        Schema::create('appraisal_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_evaluation_id')->constrained()->cascadeOnDelete();
            // Who writes it.
            $table->foreignId('reviewer_id')->constrained('employees')->cascadeOnDelete();
            // self|manager|peer|direct_report — derived from the reporting line.
            $table->string('relationship', 16);
            // pending|submitted|declined|cancelled
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['performance_evaluation_id', 'reviewer_id']);
            $table->index(['reviewer_id', 'status']);
        });

        Schema::create('appraisal_review_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_review_id')->constrained()->cascadeOnDelete();
            // The appraisal line this rates; its scale is that line's snapshot.
            $table->foreignId('performance_score_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['appraisal_review_id', 'performance_score_id']);
        });

        Schema::create('goal_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // percent|number
            $table->string('measure', 16)->default('percent');
            $table->decimal('start_value', 14, 2)->default(0);
            $table->decimal('target_value', 14, 2)->default(100);
            $table->string('unit', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('performance_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // percent|number
            $table->string('measure', 16)->default('percent');
            $table->decimal('start_value', 14, 2)->default(0);
            $table->decimal('target_value', 14, 2)->default(100);
            $table->decimal('current_value', 14, 2)->default(0);
            $table->string('unit', 40)->nullable();
            // Relative importance among the person's goals for the cycle.
            $table->decimal('weight', 6, 2)->default(1);
            $table->date('due_on')->nullable();
            // active|achieved|missed|dropped
            $table->string('status', 16)->default('active');
            // on_track|at_risk|off_track — from the latest check-in.
            $table->string('health', 16)->nullable();
            $table->timestamp('last_check_in_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'evaluation_period_id']);
            $table->index(['evaluation_period_id', 'status']);
        });

        Schema::create('goal_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('value', 14, 2);
            $table->string('health', 16);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['performance_goal_id', 'created_at']);
        });

        Schema::create('calibration_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_period_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('scheduled_for')->nullable();
            // The departments it covers; null for the whole cycle.
            $table->json('department_ids')->nullable();
            // open|completed|cancelled
            $table->string('status', 16)->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['evaluation_period_id', 'status']);
        });

        Schema::create('calibration_participants', function (Blueprint $table) {
            $table->foreignId('calibration_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['calibration_session_id', 'user_id']);
        });

        Schema::create('calibration_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calibration_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('from_band')->nullable();
            $table->string('from_label')->nullable();
            $table->string('to_band');
            $table->string('to_label');
            $table->text('reason');
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['performance_evaluation_id', 'id']);
        });

        // Every employee sees and acknowledges their own appraisals, answers the
        // reviews asked of them and checks in on their goals: the built-in roles
        // of every company get it, as OrganizationProvisioner gives a new one.
        PermissionSyncer::grant(['performance.participate'], [Role::STAFF, Role::DEPARTMENT_HEAD, Role::HR_MANAGER]);
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_adjustments');
        Schema::dropIfExists('calibration_participants');
        Schema::dropIfExists('calibration_sessions');
        Schema::dropIfExists('goal_check_ins');
        Schema::dropIfExists('performance_goals');
        Schema::dropIfExists('goal_templates');
        Schema::dropIfExists('appraisal_review_scores');
        Schema::dropIfExists('appraisal_reviews');

        Schema::table('performance_evaluations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['shared_at', 'employee_comment', 'scored_band', 'scored_label', 'calibrated_at']);
        });

        // The permission row (and its role grants, by cascade) goes with the
        // registry entry on the next sync.
    }
};

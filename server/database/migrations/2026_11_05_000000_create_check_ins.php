<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mood check-ins (ADR 0102): once a month, each employee says how work is going
     * — mood, job satisfaction, engagement (UWES-3) and how often they have thought
     * about leaving. Answers are confidential: nobody at the company reads one
     * person's, only results for groups of five or more.
     *
     *  - **check_ins** — one person's answers for one month (`period`, the month's
     *    first day on the organisation's clock), changeable until the month ends.
     *    `department_id` is where they worked when they answered, so a team's past
     *    months stay theirs when someone moves. `note` is for the person alone,
     *    encrypted at rest.
     *  - `organizations.check_ins_enabled` — whether the company runs them.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('check_ins_enabled')->default(true);
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period');
            // 1 (rough) – 5 (great)
            $table->unsignedTinyInteger('mood');
            // 1 (very dissatisfied) – 5 (very satisfied)
            $table->unsignedTinyInteger('satisfaction');
            // UWES-3, each 0 (never) – 6 (always, every day)
            $table->unsignedTinyInteger('energy');
            $table->unsignedTinyInteger('enthusiasm');
            $table->unsignedTinyInteger('immersion');
            // 1 (never) – 5 (always)
            $table->unsignedTinyInteger('leave_thoughts');
            $table->text('note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['employee_id', 'period']);
            $table->index(['organization_id', 'period']);
            $table->index(['organization_id', 'department_id', 'period']);
        });

        // Everyone answers their own; HR and department heads read team results.
        PermissionSyncer::grant(['check-ins.respond'], [Role::STAFF, Role::DEPARTMENT_HEAD, Role::HR_MANAGER]);
        PermissionSyncer::grant(['check-ins.view'], [Role::DEPARTMENT_HEAD, Role::HR_MANAGER]);
        PermissionSyncer::grant(['check-ins.manage'], [Role::HR_MANAGER]);
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('check_ins_enabled');
        });
    }
};

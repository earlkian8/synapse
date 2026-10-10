<?php

use App\Models\Role;
use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recognition by everybody (ADR 0071): colleagues nominate colleagues, HR
     * approves; awards and kudos earn points; points buy rewards.
     *
     *  - **award_nominations** — one person nominating a colleague for an award
     *    type, with why; pending until HR approves it (into an award, linked
     *    here) or turns it down, or the nominator withdraws it.
     *  - **kudos** — a short thank-you from one employee to another, and the
     *    points it carried. HR can take one down (soft delete).
     *  - **point_transactions** — the points ledger: every credit and debit,
     *    signed, with what caused it. A balance is the sum; nothing is edited in
     *    place, so taking an award back posts its reversal.
     *  - **rewards** — the catalogue points are spent on: a cost, and stock when
     *    it is limited.
     *  - **reward_redemptions** — a request for a reward: the points charged,
     *    and whether HR fulfilled or declined it, or the person cancelled.
     *  - `award_types`: the points an award of the type carries, and whether
     *    it can be nominated for.
     *  - `organizations`: the points a kudos carries, and how many kudos a
     *    month one person can give with points.
     */
    public function up(): void
    {
        Schema::table('award_types', function (Blueprint $table) {
            $table->unsignedInteger('points')->default(0)->after('color');
            $table->boolean('accepts_nominations')->default(true)->after('points');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedSmallInteger('kudos_points')->default(10);
            $table->unsignedSmallInteger('kudos_monthly_limit')->default(5);
        });

        Schema::create('award_nominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('award_type_id')->constrained()->cascadeOnDelete();
            // The nominee.
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nominated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('nominator_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('reason');
            // pending|approved|rejected|withdrawn
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('employee_award_id')->nullable()->constrained('employee_awards')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('employee_id');
        });

        Schema::create('kudos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('to_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('message');
            // As credited when sent: 0 past the sender's monthly limit.
            $table->unsignedSmallInteger('points')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'created_at']);
            $table->index('to_employee_id');
            $table->index(['from_employee_id', 'created_at']);
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Signed: credits are positive, debits negative.
            $table->integer('amount');
            // award|kudos|redemption|refund|adjustment
            $table->string('kind', 16);
            $table->nullableMorphs('subject');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('employee_id');
        });

        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('cost');
            // Null: as many as are asked for.
            $table->unsignedInteger('stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // The points charged, whatever the reward costs later.
            $table->unsignedInteger('cost');
            // pending|fulfilled|declined|cancelled
            $table->string('status', 16)->default('pending');
            $table->text('note')->nullable();
            $table->text('response_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('employee_id');
        });

        // Every employee takes part: the built-in roles of every company get it,
        // as OrganizationProvisioner gives a new one.
        PermissionSyncer::grant(['awards.participate'], [Role::STAFF, Role::DEPARTMENT_HEAD, Role::HR_MANAGER]);
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('kudos');
        Schema::dropIfExists('award_nominations');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['kudos_points', 'kudos_monthly_limit']);
        });

        Schema::table('award_types', function (Blueprint $table) {
            $table->dropColumn(['points', 'accepts_nominations']);
        });
    }
};

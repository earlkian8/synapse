<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work Schedule & Holidays (Company Setup, ERD §2).
     *
     *  - **holidays** — the organisation's holiday calendar: a named date with a
     *    type (regular / special non-working / special working) and a recurring
     *    flag (repeats every year on the same month/day). Read by Leave (a holiday
     *    is not charged as a leave day) and, in time, Attendance.
     *  - **work_schedules** — already created with the org foundation; it gains
     *    soft deletes so a schedule can be archived (employees keep their history)
     *    rather than hard-deleted, matching the other Company-Setup catalogues.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            // regular|special_non_working|special_working
            $table->string('type')->default('regular');
            // Repeats every year on the same month/day (e.g. New Year, Christmas).
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('date');
            $table->index('type');
        });

        Schema::table('work_schedules', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');

        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

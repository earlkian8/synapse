<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Awards & Recognition (ERD §9 + the §2 config table). Two tenant-scoped tables:
     *
     *  - **award_types** — the Company-Setup catalogue of recognitions the
     *    organisation gives (Employee of the Month, Perfect Attendance, Spot
     *    Award…), each with a colour + active flag for the recognition feed.
     *  - **employee_awards** — one recognition given to an employee: the type, the
     *    date, the reason, and who granted it.
     */
    public function up(): void
    {
        Schema::create('award_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Optional accent colour (hex) for the recognition UI.
            $table->string('color')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('employee_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('award_type_id')->constrained()->cascadeOnDelete();
            $table->date('awarded_on');
            $table->text('reason')->nullable();
            // Who granted the recognition (an authenticated user).
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('employee_id');
            $table->index('award_type_id');
            $table->index('awarded_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_awards');
        Schema::dropIfExists('award_types');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('suffix')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('profile_photo')->nullable();
            $table->string('employee_id')->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
        });

        // Accounts may be provisioned without a password (e.g. invited users).
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'suffix',
                'phone_number',
                'profile_photo',
                'employee_id',
                'is_active',
                'last_login_at',
                'password_changed_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};

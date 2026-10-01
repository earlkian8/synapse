<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one-time code an address is confirmed with.
     *
     * Verification used to be a signed link the recipient clicked, so nothing
     * about it had to be stored — the URL carried its own proof. A code has to be
     * remembered between the mail going out and the person typing it back, so it
     * lives here: **hashed**, exactly like a password reset token, because the
     * column is readable by anything that can read the row and possession of the
     * code is what verifies the address.
     *
     * Both columns are cleared the moment the address is verified.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_verification_code')->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_code_expires_at')->nullable()->after('email_verification_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verification_code', 'email_verification_code_expires_at']);
        });
    }
};

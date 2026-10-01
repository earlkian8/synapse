<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforced email verification (User now implements MustVerifyEmail) would
     * otherwise lock out every existing account whose address was never
     * verified. Treat all accounts that exist at migration time as already
     * verified; the confirmation requirement then applies only to accounts
     * created — or given a new email — from here on.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Irreversible: we cannot tell which rows were originally unverified.
     */
    public function down(): void
    {
        //
    }
};

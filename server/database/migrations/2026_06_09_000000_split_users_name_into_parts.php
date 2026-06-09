<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add the new columns as nullable first so the change is safe on a
        // table that already contains rows.
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
        });

        // Backfill the new columns from the legacy single "name" column.
        foreach (DB::table('users')->get(['id', 'name']) as $user) {
            $parts = preg_split('/\s+/', trim((string) $user->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $first = array_shift($parts) ?? '';
            $last = count($parts) > 0 ? array_pop($parts) : '';
            $middle = count($parts) > 0 ? implode(' ', $parts) : null;

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
            ]);
        }

        // First and last name are required; middle name stays nullable.
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable();
        });

        // Recompose the single "name" column from the split columns.
        foreach (DB::table('users')->get(['id', 'first_name', 'middle_name', 'last_name']) as $user) {
            $name = trim(implode(' ', array_filter([
                $user->first_name,
                $user->middle_name,
                $user->last_name,
            ])));

            DB::table('users')->where('id', $user->id)->update(['name' => $name]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
        });
    }
};

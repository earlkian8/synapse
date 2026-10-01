<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the *total* unique on `departments (organization_id, code)`.
     *
     * Two indexes ended up covering the same pair. `add_multi_tenancy` created a
     * total one, and `make_department_code_unique_per_tenant` later added a
     * partial one (`WHERE deleted_at IS NULL`) but only dropped the older global
     * `code` unique — so the total index survived and silently overrode the
     * partial one's whole purpose.
     *
     * The effect was that archiving a department never freed its code: creating a
     * replacement with the same code failed on a constraint the application did
     * not know about, and `DepartmentController::restore()`'s clash guard — which
     * exists precisely because the code *should* be reusable while archived —
     * could never be reached.
     *
     * The partial index is the one to keep: unique among live rows, ignoring the
     * archived.
     */
    public function up(): void
    {
        // Created via `$table->unique()`, which Postgres backs with a constraint;
        // the constraint has to go before its index can. SQLite has only the
        // plain index, so the second statement is the one that does the work.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE departments DROP CONSTRAINT IF EXISTS departments_organization_id_code_unique');
        }

        DB::statement('DROP INDEX IF EXISTS departments_organization_id_code_unique');
    }

    /**
     * Reverse the migration.
     *
     * This can legitimately fail where a code is held by both a live and an
     * archived department — which is exactly the state the total index forbade
     * and this migration allows. Clear the duplicates first if you need to roll
     * back.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->unique(['organization_id', 'code']);
        });
    }
};

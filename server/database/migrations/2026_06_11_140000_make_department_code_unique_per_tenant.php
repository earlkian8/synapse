<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make `departments.code` unique **per tenant** instead of globally.
     *
     * The original table declared `code` globally unique, which is wrong under
     * multi-tenancy (ADR 0005) — two organisations could never share a code such as
     * "HR". Replace it with a partial composite unique on `(organization_id, code)`
     * that ignores soft-deleted rows, so archiving a department frees its code.
     */
    public function up(): void
    {
        // The original global unique may exist as either a constraint or a plain
        // index depending on the grammar — drop whichever form is present.
        DB::statement('ALTER TABLE departments DROP CONSTRAINT IF EXISTS departments_code_unique');
        DB::statement('DROP INDEX IF EXISTS departments_code_unique');

        DB::statement(
            'CREATE UNIQUE INDEX departments_org_code_unique ON departments (organization_id, code) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS departments_org_code_unique');
        DB::statement('CREATE UNIQUE INDEX departments_code_unique ON departments (code)');
    }
};

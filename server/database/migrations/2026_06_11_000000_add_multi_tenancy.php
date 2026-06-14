<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn SYNAPSE into a multi-tenant system (ADR 0005).
     *
     * Creates the `organizations` table (also the company profile) and stamps every
     * tenant-owned table with a non-null `organization_id`. Existing data — the dev
     * account, seeded users, employees, roles — is preserved by backfilling it into a
     * single "Default Organization". Global unique constraints that should now be
     * unique *per organisation* (role name, department code, employee number) become
     * composite. Postgres runs this in a transaction, so a failure rolls back cleanly.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('logo')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('tin')->nullable();
            $table->string('sss_employer_no')->nullable();
            $table->string('philhealth_employer_no')->nullable();
            $table->string('pagibig_employer_no')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // If the install already holds data, fold it all into one default tenant.
        $defaultId = $this->resolveDefaultOrganizationId();

        // Tenant-owned tables that need scoping. `activity_logs` stays nullable —
        // system events may be raised with no tenant.
        $tables = [
            'users', 'roles', 'employees', 'departments', 'positions',
            'work_schedules', 'employee_documents', 'employee_certifications',
            'employee_promotions',
        ];

        foreach ($tables as $name) {
            $this->addOrganizationColumn($name, $defaultId, nullable: false);
        }

        $this->addOrganizationColumn('activity_logs', $defaultId, nullable: true);

        // Uniqueness that is now per-organisation rather than global.
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['organization_id', 'name']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['employee_no']);
            $table->unique(['organization_id', 'employee_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'name']);
            $table->unique(['name']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'code']);
            $table->unique(['code']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'employee_no']);
            $table->unique(['employee_no']);
        });

        foreach ([
            'activity_logs', 'employee_promotions', 'employee_certifications',
            'employee_documents', 'work_schedules', 'positions', 'departments',
            'employees', 'roles', 'users',
        ] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
            });
        }

        Schema::dropIfExists('organizations');
    }

    /**
     * Create the catch-all "Default Organization" when there is existing data to
     * preserve; on a fresh install there is nothing to backfill yet.
     */
    private function resolveDefaultOrganizationId(): ?int
    {
        $hasData = DB::table('users')->exists()
            || DB::table('employees')->exists()
            || DB::table('departments')->exists()
            || DB::table('roles')->exists();

        if (! $hasData) {
            return null;
        }

        return DB::table('organizations')->insertGetId([
            'name' => 'Default Organization',
            'slug' => 'default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Add an `organization_id` column to a table, backfill existing rows to the
     * default tenant, optionally enforce NOT NULL, then add the foreign key.
     */
    private function addOrganizationColumn(string $table, ?int $defaultId, bool $nullable): void
    {
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('organization_id')->nullable()->after('id');
            $blueprint->index('organization_id');
        });

        if ($defaultId !== null) {
            DB::table($table)->whereNull('organization_id')->update(['organization_id' => $defaultId]);
        }

        if (! $nullable) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN organization_id SET NOT NULL");
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }
};

<?php

use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data Export (ADR 0066): an organisation takes a copy of its own records — the
     * screen the sidebar's *Data Backup & Export* entry always promised, built as an
     * export because the database itself is backed up by its host (Supabase).
     *
     *  - **data_exports** — one archive somebody asked for: which datasets, in which
     *    format, with or without the uploaded files; where it was written and how
     *    big it is; how far it got (queued → building → ready | failed, and expired
     *    once its file is gone); a summary of what went in (rows per table, files);
     *    when it may be downloaded until, and how often it was.
     *
     * Grown from the draft ERD's `BACKUP` (disk, path, type, size_bytes, created_by,
     * completed_at): `type` (database|files|full) becomes `datasets` + `include_files`,
     * since one tenant in a shared database is exported, never dumped.
     */
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // queued|building|ready|failed|expired.
            $table->string('status')->default('queued');
            // csv|json.
            $table->string('format')->default('csv');
            // The dataset keys asked for (DataExportCatalogue::DATASETS).
            $table->json('datasets');
            $table->boolean('include_files')->default(false);

            // Where the archive was written, on the private `exports` disk.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // Rows per table per dataset, and the files that went in or were missing.
            $table->json('summary')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->timestamps();

            // The prune reads across organisations; the screen and the
            // one-at-a-time check read one organisation's exports by status.
            $table->index('status');
            $table->index('expires_at');
            $table->index(['organization_id', 'status']);
        });

        // `data-export.view` / `.create` are new; publish them, then grant both to
        // every HR Manager, as OrganizationProvisioner does for a new tenant.
        PermissionSyncer::sync();

        $permissionIds = DB::table('permissions')->whereIn('name', ['data-export.view', 'data-export.create'])->pluck('id');

        foreach (DB::table('roles')->where('name', 'hr-manager')->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $exists = DB::table('permission_role')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');

        // The permission rows (and their role grants, by cascade) go with the
        // registry entries on the next sync.
    }
};

<?php

use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The shift roster moves back into Attendance (ADR 0090), so its two
     * abilities are renamed `setup.roster.*` → `attendance.roster.*` in place,
     * and every role that held them still does. Nothing else changes: the
     * roster's tables never belonged to either module.
     */
    public function up(): void
    {
        $this->rename('setup.roster.view', 'attendance.roster.view');
        $this->rename('setup.roster.manage', 'attendance.roster.manage');

        PermissionSyncer::sync();
    }

    /**
     * The abilities return to their Company Setup names, grants and all.
     */
    public function down(): void
    {
        $this->rename('attendance.roster.view', 'setup.roster.view');
        $this->rename('attendance.roster.manage', 'setup.roster.manage');
    }

    /**
     * Rename a permission, keeping the roles that hold it. When the new name
     * already exists (a sync ran first), its grants are merged into it instead.
     * The sync in `up()` brings the label and group along.
     */
    private function rename(string $from, string $to): void
    {
        $old = DB::table('permissions')->where('name', $from)->value('id');

        if ($old === null) {
            return;
        }

        $new = DB::table('permissions')->where('name', $to)->value('id');

        if ($new === null) {
            DB::table('permissions')->where('id', $old)->update(['name' => $to]);

            return;
        }

        foreach (DB::table('permission_role')->where('permission_id', $old)->pluck('role_id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $new]);
        }

        DB::table('permissions')->where('id', $old)->delete();
    }
};

<?php

use App\Support\PermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performance goes back to the appraisal alone: the reviews asked of the
     * people around it, the employee's own acknowledgement, goals with their
     * check-ins and calibration sessions are removed (ADRs 0072 and 0073 are
     * withdrawn), with everything `2026_10_10_020000` added for them.
     *
     *  - A rating a calibration session moved returns to what the scorecard
     *    gave (`scored_band` / `scored_label`), as the appraisal alone decides.
     *  - Notifications that open a removed screen (My appraisals, My goals,
     *    Reviews, Goals, Calibration) are deleted rather than left to 404.
     *  - `performance.participate` is pruned with its grants by the sync.
     */
    public function up(): void
    {
        if (Schema::hasColumn('performance_evaluations', 'scored_band')) {
            DB::table('performance_evaluations')
                ->whereNotNull('scored_band')
                ->update([
                    'result_band' => DB::raw('scored_band'),
                    'result_label' => DB::raw('scored_label'),
                ]);
        }

        $this->forgetNotifications();

        Schema::dropIfExists('calibration_adjustments');
        Schema::dropIfExists('calibration_participants');
        Schema::dropIfExists('calibration_sessions');
        Schema::dropIfExists('goal_check_ins');
        Schema::dropIfExists('performance_goals');
        Schema::dropIfExists('goal_templates');
        Schema::dropIfExists('appraisal_review_scores');
        Schema::dropIfExists('appraisal_reviews');

        if (Schema::hasColumn('performance_evaluations', 'shared_at')) {
            Schema::table('performance_evaluations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('acknowledged_by');
                $table->dropColumn(['shared_at', 'employee_comment', 'scored_band', 'scored_label', 'calibrated_at']);
            });
        }

        PermissionSyncer::sync();
    }

    /**
     * The tables and columns come back empty; what was removed is not restored.
     */
    public function down(): void
    {
        (require __DIR__.'/2026_10_10_020000_create_performance_self_service_tables.php')->up();
    }

    /**
     * Delete the in-app notifications whose link leads to a removed screen.
     * `data` is text on Postgres, so the link is read in PHP.
     */
    private function forgetNotifications(): void
    {
        $removed = '#^/performance/(me|reviews|goals|calibration)(/|\?|$)#';

        $ids = DB::table('notifications')
            ->where('data', 'like', '%performance%')
            ->get(['id', 'data'])
            ->filter(fn (object $row): bool => preg_match($removed, (string) (json_decode($row->data, true)['url'] ?? '')) === 1)
            ->pluck('id');

        foreach ($ids->chunk(500) as $chunk) {
            DB::table('notifications')->whereIn('id', $chunk->all())->delete();
        }
    }
};

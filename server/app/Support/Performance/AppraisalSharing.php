<?php

namespace App\Support\Performance;

use App\Models\CalibrationSession;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use App\Support\Notifier;

/**
 * When an employee gets to see their appraisal (ADRs 0072, 0073).
 *
 * A submitted appraisal is **shared** straight away — unless an open calibration
 * session covers it, in which case the result is held until the session is
 * completed or cancelled, so nobody reads a rating that is about to move. Sharing
 * is also the moment the employee is told it is ready to read and acknowledge.
 *
 * {@see AppraisalWorkflow} asks it on submit; {@see CalibrationWorkflow} releases
 * a session's appraisals through it.
 */
class AppraisalSharing
{
    /**
     * The open calibration session holding this appraisal back, if any.
     */
    public function holdingSession(PerformanceEvaluation $evaluation): ?CalibrationSession
    {
        $departmentId = $evaluation->employee()->value('department_id');

        return CalibrationSession::query()
            ->open()
            ->where('evaluation_period_id', $evaluation->evaluation_period_id)
            ->orderBy('id')
            ->get()
            ->first(fn (CalibrationSession $session): bool => $session->covers($departmentId === null ? null : (int) $departmentId));
    }

    /**
     * Share a submitted appraisal unless a session holds it. Returns the session
     * holding it, or null once it is shared.
     */
    public function shareUnlessHeld(PerformanceEvaluation $evaluation): ?CalibrationSession
    {
        $holding = $this->holdingSession($evaluation);

        if ($holding === null) {
            $this->share($evaluation);
        }

        return $holding;
    }

    /**
     * Let the employee see the result, and tell them it is ready.
     */
    public function share(PerformanceEvaluation $evaluation): void
    {
        if ($evaluation->shared_at !== null || $evaluation->status === 'draft') {
            return;
        }

        $evaluation->forceFill(['shared_at' => now()])->save();

        $this->tellEmployee(
            $evaluation,
            'Your appraisal is ready',
            "Your {$this->cycleName($evaluation)} appraisal is ready to read. Acknowledge it once you've gone through it.",
        );
    }

    /**
     * Share every held appraisal a session covered — when it completes or is
     * cancelled.
     */
    public function release(CalibrationSession $session): int
    {
        $held = $session->evaluations()
            ->where('status', 'submitted')
            ->whereNull('shared_at')
            ->with('employee:id,user_id,first_name,last_name', 'period:id,name')
            ->get();

        foreach ($held as $evaluation) {
            $this->share($evaluation);
        }

        return $held->count();
    }

    /**
     * Notify the person appraised, when they have an active account.
     */
    public function tellEmployee(PerformanceEvaluation $evaluation, string $title, string $body, string $level = 'info'): void
    {
        $user = $this->employeeUser($evaluation);

        if ($user !== null) {
            Notifier::toUser($user, $title, $body, self::employeeUrl($evaluation), $level, 'performance');
        }
    }

    /**
     * Where the employee reads their appraisal.
     */
    public static function employeeUrl(PerformanceEvaluation $evaluation): string
    {
        return '/performance/me/'.$evaluation->hashid;
    }

    private function employeeUser(PerformanceEvaluation $evaluation): ?User
    {
        $user = $evaluation->employee?->user;

        return $user !== null && $user->is_active ? $user : null;
    }

    private function cycleName(PerformanceEvaluation $evaluation): string
    {
        return $evaluation->period?->name ?? 'review cycle';
    }
}

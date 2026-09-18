<?php

namespace App\Support\Setup;

use App\Http\Controllers\Setup\SetupWizardController;
use App\Http\Middleware\RequireCompanySetup;
use App\Models\Organization;

/**
 * Where a company is in guided setup, and the vocabulary the wizard is built
 * from.
 *
 * Registration provisions an empty tenant (ADR 0005) and the configuration-driven
 * modules deliberately ship no defaults, so a brand-new organisation has ten
 * Company Setup screens and no stated order. This class names the handful that
 * actually block day-one work, records what the owner did with each, and answers
 * the one question {@see RequireCompanySetup} asks on every request: is this
 * company still waiting to be set up?
 *
 * Progress lives on the tenant itself (`organizations.setup_steps` /
 * `setup_completed_at`), not in the session, so it survives a sign-out, follows
 * the company across devices, and is the same answer for every owner of it.
 * See {@see SetupWizardController} for the screens themselves.
 */
class CompanySetup
{
    public const COMPANY = 'company';

    public const DEPARTMENTS = 'departments';

    public const LEAVE_TYPES = 'leave-types';

    public const ATTENDANCE = 'attendance';

    public const RECRUITMENT = 'recruitment';

    public const PERFORMANCE = 'performance';

    /** The steps, in the order the wizard walks them. */
    public const STEPS = [
        self::COMPANY,
        self::DEPARTMENTS,
        self::LEAVE_TYPES,
        self::ATTENDANCE,
        self::RECRUITMENT,
        self::PERFORMANCE,
    ];

    /** A step the owner completed. */
    public const DONE = 'done';

    /** A step the owner passed over — deliberate, and remembered as such. */
    public const SKIPPED = 'skipped';

    /** A step not yet answered either way. */
    public const PENDING = 'pending';

    /**
     * The permission each step's work needs. A step configures a real module, so
     * it is gated by that module's own ability rather than by "can run the
     * wizard" — the wizard is a route through Company Setup, not a way around it.
     *
     * @var array<string, string>
     */
    public const ABILITIES = [
        self::COMPANY => 'setup.company.manage',
        self::DEPARTMENTS => 'setup.departments.manage',
        self::LEAVE_TYPES => 'setup.leave-types.manage',
        self::ATTENDANCE => 'setup.attendance-policies.manage',
        self::RECRUITMENT => 'recruitment.configure-pipelines',
        self::PERFORMANCE => 'setup.kpi.manage',
    ];

    /**
     * Record what happened to one step. Written with `forceFill` because progress
     * is tenant state rather than a profile field (same reasoning as the join
     * code) — see {@see Organization::casts()}.
     */
    public static function markStep(Organization $organization, string $step, string $status): void
    {
        if (! in_array($step, self::STEPS, true)) {
            return;
        }

        $steps = $organization->setup_steps ?? [];
        $steps[$step] = $status;

        $organization->forceFill(['setup_steps' => $steps])->save();
    }

    /**
     * Close setup for this company. Idempotent — finishing an already-finished
     * setup (a second owner reaching the end, a double submit) leaves the
     * original completion date alone.
     */
    public static function complete(Organization $organization): void
    {
        if ($organization->hasFinishedSetup()) {
            return;
        }

        $organization->forceFill(['setup_completed_at' => now()])->save();
    }

    /**
     * Every step's recorded status, with the ones never answered reading as
     * {@see PENDING} rather than being absent.
     *
     * @return array<string, string>
     */
    public static function statuses(Organization $organization): array
    {
        $stored = $organization->setup_steps ?? [];

        $statuses = [];

        foreach (self::STEPS as $step) {
            $status = $stored[$step] ?? null;

            $statuses[$step] = in_array($status, [self::DONE, self::SKIPPED], true)
                ? $status
                : self::PENDING;
        }

        return $statuses;
    }

    /**
     * The step the wizard should open on: the first one still unanswered, or the
     * last one when every step has been answered (there is nothing left to
     * resume, so land where finishing happens).
     */
    public static function resumeStep(Organization $organization): string
    {
        foreach (self::statuses($organization) as $step => $status) {
            if ($status === self::PENDING) {
                return $step;
            }
        }

        return self::STEPS[array_key_last(self::STEPS)];
    }
}

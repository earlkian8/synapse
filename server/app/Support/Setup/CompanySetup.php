<?php

namespace App\Support\Setup;

use App\Http\Controllers\Setup\SetupWizardController;
use App\Http\Middleware\RequireCompanySetup;
use App\Models\AttendanceDevice;
use App\Models\AttendancePolicy;
use App\Models\AwardType;
use App\Models\Department;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\OffboardingProgram;
use App\Models\OnboardingProgram;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Models\ReviewTemplate;
use App\Models\ShiftRosterEntry;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Queries\Setup\AttendancePoliciesScreen;
use App\Queries\Setup\AwardTypesScreen;
use App\Queries\Setup\CompanyProfileScreen;
use App\Queries\Setup\DepartmentsScreen;
use App\Queries\Setup\DevicesScreen;
use App\Queries\Setup\LeaveTypesScreen;
use App\Queries\Setup\LocationsScreen;
use App\Queries\Setup\OffboardingProgramsScreen;
use App\Queries\Setup\OnboardingProgramsScreen;
use App\Queries\Setup\PerformanceFrameworkScreen;
use App\Queries\Setup\RecruitmentPipelinesScreen;
use App\Queries\Setup\SetupScreen;
use App\Queries\Setup\ShiftRosterScreen;
use App\Queries\Setup\WorkScheduleScreen;

/**
 * Where a company is in guided setup, and the vocabulary the wizard is built
 * from.
 *
 * Registration provisions an empty tenant (ADR 0005) and the configuration-driven
 * modules deliberately ship no defaults, so a brand-new organisation has every
 * Company Setup screen and no stated order. This class puts all of them in one
 * order — one wizard step per screen, each backed by the same
 * {@see SetupScreen} the screen itself renders — records what the owner did
 * with each, and answers the one question {@see RequireCompanySetup} asks on
 * every request: is this company still waiting to be set up?
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

    public const ATTENDANCE = 'attendance';

    public const SCHEDULE = 'schedule';

    public const LEAVE_TYPES = 'leave-types';

    public const LOCATIONS = 'locations';

    public const DEVICES = 'devices';

    public const ROSTER = 'roster';

    public const RECRUITMENT = 'recruitment';

    public const ONBOARDING = 'onboarding';

    public const PERFORMANCE = 'performance';

    public const AWARDS = 'awards';

    public const OFFBOARDING = 'offboarding';

    /**
     * The steps, in the order the wizard walks them: the company and its shape,
     * then time (how a day is judged, when people work, the time they take off,
     * where and how they clock in, who works which shift), then a person's life
     * at the company from hire to exit. Where one step's options come from an
     * earlier one — a site's default schedule, a device's site, a roster's
     * schedules — the earlier one comes first.
     */
    public const STEPS = [
        self::COMPANY,
        self::DEPARTMENTS,
        self::ATTENDANCE,
        self::SCHEDULE,
        self::LEAVE_TYPES,
        self::LOCATIONS,
        self::DEVICES,
        self::ROSTER,
        self::RECRUITMENT,
        self::ONBOARDING,
        self::PERFORMANCE,
        self::AWARDS,
        self::OFFBOARDING,
    ];

    /** The welcome, shown to a company that has answered nothing yet. */
    public const INTRO = 'intro';

    /** The send-off, where setup is finished. */
    public const FINISH = 'done';

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
        self::ATTENDANCE => 'setup.attendance-policies.manage',
        self::SCHEDULE => 'setup.schedule.manage',
        self::LEAVE_TYPES => 'setup.leave-types.manage',
        self::LOCATIONS => 'setup.locations.manage',
        self::DEVICES => 'setup.devices.manage',
        self::ROSTER => 'setup.roster.manage',
        self::RECRUITMENT => 'recruitment.configure-pipelines',
        self::ONBOARDING => 'onboarding.manage-programs',
        self::PERFORMANCE => 'setup.kpi.manage',
        self::AWARDS => 'setup.award-types.manage',
        self::OFFBOARDING => 'offboarding.manage-programs',
    ];

    /**
     * The Company Setup screen behind each step. A step shows what its screen
     * shows and offers what its screen offers, so everything a company can do
     * under Company Setup it can do here.
     *
     * @var array<string, class-string<SetupScreen>>
     */
    public const SCREENS = [
        self::COMPANY => CompanyProfileScreen::class,
        self::DEPARTMENTS => DepartmentsScreen::class,
        self::ATTENDANCE => AttendancePoliciesScreen::class,
        self::SCHEDULE => WorkScheduleScreen::class,
        self::LEAVE_TYPES => LeaveTypesScreen::class,
        self::LOCATIONS => LocationsScreen::class,
        self::DEVICES => DevicesScreen::class,
        self::ROSTER => ShiftRosterScreen::class,
        self::RECRUITMENT => RecruitmentPipelinesScreen::class,
        self::ONBOARDING => OnboardingProgramsScreen::class,
        self::PERFORMANCE => PerformanceFrameworkScreen::class,
        self::AWARDS => AwardTypesScreen::class,
        self::OFFBOARDING => OffboardingProgramsScreen::class,
    ];

    /**
     * Every view the wizard can be opened on: the welcome, each step, and the
     * send-off.
     *
     * @return list<string>
     */
    public static function views(): array
    {
        return [self::INTRO, ...self::STEPS, self::FINISH];
    }

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

    /**
     * Where the wizard opens when no view is asked for. A company that has
     * answered nothing has not started; one that has answered everything, or
     * already finished, has only the send-off left. Anything in between resumes
     * where it left off rather than at the welcome again.
     */
    public static function initialView(Organization $organization): string
    {
        if ($organization->hasFinishedSetup()) {
            return self::FINISH;
        }

        $pending = array_keys(array_filter(
            self::statuses($organization),
            fn (string $status): bool => $status === self::PENDING,
        ));

        return match (count($pending)) {
            count(self::STEPS) => self::INTRO,
            0 => self::FINISH,
            default => self::resumeStep($organization),
        };
    }

    /**
     * Whether each step's module already holds something to continue with — the
     * difference between "Continue" and "Skip" on a step that was configured on
     * its Company Setup screen, or by the step's own actions, rather than through
     * its suggestions.
     *
     * The company profile always exists, so its step is always configured. The
     * roster is configured once anybody has something to be rostered on: a
     * company default schedule, an assignment, or a one-off shift.
     *
     * @return array<string, bool>
     */
    public static function configured(Organization $organization): array
    {
        return [
            self::COMPANY => true,
            self::DEPARTMENTS => Department::query()->exists(),
            self::ATTENDANCE => AttendancePolicy::query()->exists(),
            self::SCHEDULE => WorkSchedule::query()->exists() || Holiday::query()->exists(),
            self::LEAVE_TYPES => LeaveType::query()->exists(),
            self::LOCATIONS => WorkLocation::query()->exists(),
            self::DEVICES => AttendanceDevice::query()->exists(),
            self::ROSTER => $organization->default_work_schedule_id !== null
                || EmployeeScheduleAssignment::query()->exists()
                || ShiftRosterEntry::query()->exists(),
            self::RECRUITMENT => RecruitmentPipeline::query()->exists(),
            self::ONBOARDING => OnboardingProgram::query()->exists(),
            self::PERFORMANCE => ReviewTemplate::query()->exists(),
            self::AWARDS => AwardType::query()->exists(),
            self::OFFBOARDING => OffboardingProgram::query()->exists(),
        ];
    }
}

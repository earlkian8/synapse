<?php

namespace App\Support\DataExport;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a Data Export can hold (ADR 0066): the organisation's records, grouped into
 * datasets the way the sidebar groups its screens.
 *
 * Each dataset is guarded by the **view permission of the screen its records are
 * read on**, so an export never hands anybody more than the screens already
 * would — the Trash Bin's rule (ADR 0057). Each table is read raw, every column
 * (archived rows included), less the columns in `exclude`, which are sign-in
 * secrets rather than records.
 *
 * A table is confined to the organisation **explicitly** by its `scope`, never by
 * the tenant global scope alone, which is a no-op when no tenant is bound:
 *
 *  - `tenant` (the default) — `organization_id` is the organisation's;
 *  - `self` — the organisation's own row;
 *  - `members` — accounts with a membership in it (users are global identities);
 *  - `roles` — pivot rows of its roles;
 *  - `work_locations` — pivot rows of its work locations;
 *  - `calibration_sessions` — pivot rows of its calibration sessions;
 *  - `global` — a catalogue shared by every organisation (permission names).
 *
 * `files` names the columns that hold a path on the `public` disk, copied into
 * the archive when the uploaded files are asked for. Every table that carries an
 * `organization_id` is either here or in {@see self::EXCLUDED}, with the reason;
 * a test holds that true as tables are added.
 */
final class DataExportCatalogue
{
    /**
     * In the sidebar's order.
     *
     * @var array<string, array{label: string, section: string, description: string, permission: string, tables: list<array{table: string, scope?: string, where?: array<string, string>, exclude?: list<string>, files?: list<string>, order?: list<string>}>}>
     */
    public const DATASETS = [
        // ── Talent Acquisition ───────────────────────────────────────────────
        'recruitment' => [
            'label' => 'Recruitment', 'section' => 'Talent Acquisition', 'permission' => 'recruitment.view',
            'description' => 'Job postings and their screening questions, pipelines, applicants and their documents, applications and interviews.',
            'tables' => [
                ['table' => 'recruitment_pipelines'],
                ['table' => 'recruitment_pipeline_stages'],
                ['table' => 'job_postings'],
                ['table' => 'job_posting_screening_questions'],
                ['table' => 'applicants', 'files' => ['resume']],
                ['table' => 'applicant_documents', 'files' => ['file']],
                ['table' => 'job_applications'],
                ['table' => 'interviews'],
            ],
        ],
        'onboarding' => [
            'label' => 'Onboarding', 'section' => 'Talent Acquisition', 'permission' => 'onboarding.view',
            'description' => 'Onboarding programs and their tasks, and every new hire\'s checklist.',
            'tables' => [
                ['table' => 'onboarding_programs'],
                ['table' => 'onboarding_program_tasks'],
                ['table' => 'onboarding_cases'],
                ['table' => 'onboarding_tasks'],
            ],
        ],

        // ── Workforce ────────────────────────────────────────────────────────
        'employees' => [
            'label' => 'Employees', 'section' => 'Workforce', 'permission' => 'employees.view',
            'description' => 'Every 201 file — personal, employment, pay and government details — with documents, certifications, promotions, invitations and join requests.',
            'tables' => [
                ['table' => 'employees', 'files' => ['photo']],
                ['table' => 'employee_documents', 'files' => ['file']],
                ['table' => 'employee_certifications', 'files' => ['file']],
                ['table' => 'employee_promotions'],
                ['table' => 'employee_invitations', 'exclude' => ['token', 'code']],
                ['table' => 'organization_join_requests'],
            ],
        ],
        'attendance' => [
            'label' => 'Attendance', 'section' => 'Workforce', 'permission' => 'attendance.view',
            'description' => 'Daily time records with their minute buckets and the rules they were judged by, and every punch with where and how it was captured.',
            'tables' => [
                ['table' => 'attendance_records'],
                ['table' => 'attendance_punches', 'files' => ['photo']],
            ],
        ],
        'leave' => [
            'label' => 'Leave', 'section' => 'Workforce', 'permission' => 'leave.view',
            'description' => 'Leave types, yearly entitlements and every request with its review.',
            'tables' => [
                ['table' => 'leave_types'],
                ['table' => 'leave_balances'],
                ['table' => 'leave_requests'],
            ],
        ],
        'performance' => [
            'label' => 'Performance', 'section' => 'Workforce', 'permission' => 'performance.view',
            'description' => 'The appraisal framework — rating scales, criteria, review templates, the goal library, cycles — every evaluation with its scores, the reviews asked for it, goals and their check-ins, and calibration sessions with every rating they moved.',
            'tables' => [
                ['table' => 'rating_scales'],
                ['table' => 'kpi_criteria'],
                ['table' => 'review_templates'],
                ['table' => 'review_template_items'],
                ['table' => 'goal_templates'],
                ['table' => 'evaluation_periods'],
                ['table' => 'performance_evaluations'],
                ['table' => 'performance_scores'],
                ['table' => 'appraisal_reviews'],
                ['table' => 'appraisal_review_scores'],
                ['table' => 'performance_goals'],
                ['table' => 'goal_check_ins'],
                ['table' => 'calibration_sessions'],
                ['table' => 'calibration_participants', 'scope' => 'calibration_sessions', 'order' => ['calibration_session_id', 'user_id']],
                ['table' => 'calibration_adjustments'],
            ],
        ],
        'training' => [
            'label' => 'Training', 'section' => 'Workforce', 'permission' => 'training.view',
            'description' => 'Training programs and who enrolled, with scores and completion.',
            'tables' => [
                ['table' => 'training_programs'],
                ['table' => 'training_enrollments'],
            ],
        ],
        'awards' => [
            'label' => 'Awards', 'section' => 'Workforce', 'permission' => 'awards.view',
            'description' => 'Award types and every recognition given, nominations, kudos, the points ledger, and the rewards catalogue with its requests.',
            'tables' => [
                ['table' => 'award_types'],
                ['table' => 'employee_awards'],
                ['table' => 'award_nominations'],
                ['table' => 'kudos'],
                ['table' => 'point_transactions'],
                ['table' => 'rewards'],
                ['table' => 'reward_redemptions'],
            ],
        ],
        'events' => [
            'label' => 'Events', 'section' => 'Workforce', 'permission' => 'events.view',
            'description' => 'Events and meetings, their repeat rules and rooms, and every invitee\'s reply.',
            'tables' => [
                ['table' => 'rooms'],
                ['table' => 'event_series'],
                ['table' => 'events'],
                ['table' => 'event_attendees'],
            ],
        ],

        // ── Offboarding ──────────────────────────────────────────────────────
        'offboarding' => [
            'label' => 'Offboarding', 'section' => 'Offboarding', 'permission' => 'offboarding.view',
            'description' => 'Clearance templates, exit cases and every clearance sign-off.',
            'tables' => [
                ['table' => 'offboarding_programs'],
                ['table' => 'offboarding_program_items'],
                ['table' => 'offboarding_cases'],
                ['table' => 'clearance_items'],
            ],
        ],

        // ── Analytics & AI ───────────────────────────────────────────────────
        'attrition' => [
            'label' => 'Attrition risk', 'section' => 'Analytics & AI', 'permission' => 'analytics.attrition.view',
            'description' => 'Every assessment run with each employee\'s score and its factors, and your own trained models.',
            'tables' => [
                ['table' => 'attrition_risk_runs'],
                ['table' => 'attrition_risk_scores'],
                ['table' => 'local_models', 'where' => ['model' => 'attrition']],
            ],
        ],
        'performance-forecast' => [
            'label' => 'Performance forecast', 'section' => 'Analytics & AI', 'permission' => 'analytics.performance.view',
            'description' => 'Every forecast run with each employee\'s projected rating, and your own trained models.',
            'tables' => [
                ['table' => 'performance_forecast_runs'],
                ['table' => 'performance_forecasts'],
                ['table' => 'local_models', 'where' => ['model' => 'performance']],
            ],
        ],
        'promotion-readiness' => [
            'label' => 'Promotion readiness', 'section' => 'Analytics & AI', 'permission' => 'analytics.promotion.view',
            'description' => 'Every assessment run with each employee\'s readiness score, and your own trained models.',
            'tables' => [
                ['table' => 'promotion_readiness_runs'],
                ['table' => 'promotion_readiness_scores'],
                ['table' => 'local_models', 'where' => ['model' => 'promotion']],
            ],
        ],

        // ── Company Setup ────────────────────────────────────────────────────
        'company' => [
            'label' => 'Company profile', 'section' => 'Company Setup', 'permission' => 'setup.company.view',
            'description' => 'Your company\'s identity, contact details, logo and employer registration numbers.',
            'tables' => [
                ['table' => 'organizations', 'scope' => 'self', 'exclude' => ['join_code'], 'files' => ['logo']],
            ],
        ],
        'structure' => [
            'label' => 'Departments & positions', 'section' => 'Company Setup', 'permission' => 'setup.departments.view',
            'description' => 'The department tree and the positions in each, with their salary grades.',
            'tables' => [
                ['table' => 'departments'],
                ['table' => 'positions'],
            ],
        ],
        'schedules' => [
            'label' => 'Work schedules & holidays', 'section' => 'Company Setup', 'permission' => 'setup.schedule.view',
            'description' => 'Every shift pattern day by day, and the holiday calendar.',
            'tables' => [
                ['table' => 'work_schedules'],
                ['table' => 'work_schedule_days'],
                ['table' => 'holidays'],
            ],
        ],
        'roster' => [
            'label' => 'Shift roster', 'section' => 'Company Setup', 'permission' => 'setup.roster.view',
            'description' => 'Who was assigned which schedule and when, and every one-off shift override.',
            'tables' => [
                ['table' => 'employee_schedule_assignments'],
                ['table' => 'shift_roster_entries'],
            ],
        ],
        'attendance-policies' => [
            'label' => 'Attendance policies', 'section' => 'Company Setup', 'permission' => 'setup.attendance-policies.view',
            'description' => 'How a working day is judged: each policy and its settings.',
            'tables' => [
                ['table' => 'attendance_policies'],
            ],
        ],
        'locations' => [
            'label' => 'Work locations', 'section' => 'Company Setup', 'permission' => 'setup.locations.view',
            'description' => 'Work sites with their clock-in fences, and who is based where.',
            'tables' => [
                ['table' => 'work_locations'],
                ['table' => 'employee_work_locations', 'scope' => 'work_locations'],
            ],
        ],

        // ── System ───────────────────────────────────────────────────────────
        'users' => [
            'label' => 'User accounts', 'section' => 'System', 'permission' => 'users.view',
            'description' => 'Everyone who can sign in to this workspace, and when they joined. Passwords and sign-in secrets are never included.',
            'tables' => [
                ['table' => 'users', 'scope' => 'members', 'exclude' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'email_verification_code', 'email_verification_code_expires_at'], 'files' => ['profile_photo']],
                ['table' => 'organization_user'],
            ],
        ],
        'roles' => [
            'label' => 'Roles & permissions', 'section' => 'System', 'permission' => 'roles.view',
            'description' => 'Your roles, who holds each, and what each one grants.',
            'tables' => [
                ['table' => 'roles'],
                ['table' => 'role_user', 'scope' => 'roles', 'order' => ['role_id', 'user_id']],
                ['table' => 'permission_role', 'scope' => 'roles', 'order' => ['role_id', 'permission_id']],
                ['table' => 'permissions', 'scope' => 'global'],
            ],
        ],
        'activity-logs' => [
            'label' => 'Activity logs', 'section' => 'System', 'permission' => 'activity-logs.view',
            'description' => 'The audit trail: who did what, and when.',
            'tables' => [
                ['table' => 'activity_logs'],
            ],
        ],
    ];

    /**
     * Tables with an `organization_id` that are never exported, and why.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        'assistant_conversations' => 'Each person\'s own chats with the assistant are theirs, not the company\'s records.',
        'assistant_messages' => 'Each person\'s own chats with the assistant are theirs, not the company\'s records.',
        'personal_access_tokens' => 'Sign-in credentials for the mobile app.',
        'calendar_feeds' => 'Each person\'s private calendar link — a secret that opens their invitations, not a record.',
        'data_exports' => 'This screen\'s own history of archives.',
    ];

    /**
     * Every dataset key.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DATASETS);
    }

    /**
     * One dataset's definition, or null for a key that is not one.
     *
     * @return array{label: string, section: string, description: string, permission: string, tables: list<array<string, mixed>>}|null
     */
    public static function definition(string $key): ?array
    {
        return self::DATASETS[$key] ?? null;
    }

    /**
     * Every table some dataset exports.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return collect(self::DATASETS)
            ->flatMap(fn (array $dataset): array => array_column($dataset['tables'], 'table'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a user may export a dataset: they can open the screen its records
     * are read on.
     */
    public static function allows(User $user, string $key): bool
    {
        $dataset = self::definition($key);

        return $dataset !== null && $user->can($dataset['permission']);
    }

    /**
     * The datasets a user may export, keyed as {@see self::DATASETS}.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function for(User $user): array
    {
        return collect(self::DATASETS)
            ->filter(fn (array $dataset, string $key): bool => self::allows($user, $key))
            ->all();
    }

    /**
     * Whether a table spec carries uploaded files.
     *
     * @param  array<string, mixed>  $dataset
     */
    public static function hasFiles(array $dataset): bool
    {
        return collect($dataset['tables'])->contains(fn (array $table): bool => ($table['files'] ?? []) !== []);
    }

    /**
     * The rows of one table that belong to an organisation, confined by the
     * table's scope — with no ordering or columns chosen yet.
     *
     * @param  array{table: string, scope?: string, where?: array<string, string>}  $spec
     */
    public static function query(array $spec, int $organizationId): Builder
    {
        $table = $spec['table'];
        $query = DB::table($table);

        match ($spec['scope'] ?? 'tenant') {
            'tenant' => $query->where("{$table}.organization_id", $organizationId),
            'self' => $query->where("{$table}.id", $organizationId),
            'members' => $query->whereIn("{$table}.id", DB::table('organization_user')->select('user_id')->where('organization_id', $organizationId)),
            'roles' => $query->whereIn("{$table}.role_id", DB::table('roles')->select('id')->where('organization_id', $organizationId)),
            'work_locations' => $query->whereIn("{$table}.work_location_id", DB::table('work_locations')->select('id')->where('organization_id', $organizationId)),
            'calibration_sessions' => $query->whereIn("{$table}.calibration_session_id", DB::table('calibration_sessions')->select('id')->where('organization_id', $organizationId)),
            'global' => null,
        };

        foreach ($spec['where'] ?? [] as $column => $value) {
            $query->where("{$table}.{$column}", $value);
        }

        return $query;
    }
}

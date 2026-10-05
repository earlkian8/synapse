<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What SYNAPSE is, screen by screen — for the assistant's "how do I…", "where
 * is…", "what can I do here?" (ADR 0059).
 *
 * Each entry is a real screen: where it sits in the sidebar, its address, who
 * may open it (the same permissions the sidebar and the route check), what
 * people do there, and what the assistant can do for it. The guide is always
 * read **for a user**: a screen they cannot open is not described to them, so
 * it never tells anybody about a part of the system they have no access to.
 *
 * It describes the product, not its implementation — no class names, tables or
 * internals — because everything in it may be repeated to whoever asked.
 * Keeping it in step with the sidebar (`lib/app-navigation.ts`) and the routes is
 * part of adding a screen.
 */
final class SystemGuide
{
    /**
     * @var array<string, array{title: string, menu: string, path: string, any: list<string>, about: string, tasks: list<string>, assistant: string, keywords: list<string>}>
     */
    private const SCREENS = [
        'dashboard' => [
            'title' => 'Dashboard', 'menu' => 'Main', 'path' => '/dashboard', 'any' => [],
            'about' => 'The workspace at a glance: headcount, today\'s attendance, leave, hiring, onboarding, offboarding, what needs your attention, and recent activity — only the blocks you have access to.',
            'tasks' => ['See what needs your action today', 'Jump into any module from its card'],
            'assistant' => 'Summarise how the company is doing today, list what needs your attention, and read the recent audit trail.',
            'keywords' => ['home', 'overview', 'today', 'attention', 'summary', 'pulse'],
        ],
        'recruitment' => [
            'title' => 'Recruitment', 'menu' => 'Talent Acquisition', 'path' => '/recruitment', 'any' => ['recruitment.view'],
            'about' => 'Job postings, applicants and each posting\'s hiring board (its pipeline stages), interviews, AI candidate insights, and hiring someone straight into the employee roster. Open postings get a public careers page.',
            'tasks' => ['Create a posting and publish it', 'Add applicants and move them through the stages', 'Schedule and record interviews', 'Rank candidates and read AI insights', 'Hire an applicant — they become an employee'],
            'assistant' => 'Everything on this screen: postings, applicants, stage moves, interviews, rankings and hiring.',
            'keywords' => ['hire', 'hiring', 'job', 'posting', 'vacancy', 'applicant', 'candidate', 'interview', 'careers'],
        ],
        'onboarding' => [
            'title' => 'Onboarding', 'menu' => 'Talent Acquisition', 'path' => '/onboarding', 'any' => ['onboarding.view'],
            'about' => 'A checklist for each new hire, seeded from an onboarding program, with tasks assigned to people and due dates.',
            'tasks' => ['Start onboarding for a new hire', 'Add, assign and complete tasks', 'Nudge whoever owns an overdue task'],
            'assistant' => 'Start and manage onboarding cases and their tasks, and nudge owners.',
            'keywords' => ['new hire', 'orientation', 'checklist', 'first day', 'tasks'],
        ],
        'employees' => [
            'title' => 'Employees', 'menu' => 'Workforce', 'path' => '/employees', 'any' => ['employees.view'],
            'about' => 'The HR hub: every employee\'s 201 file — personal and employment details, position and department, manager, schedule, documents, certifications and promotions.',
            'tasks' => ['Add, edit, archive and restore employees', 'Upload documents and certifications', 'Assign a work schedule', 'Export the list'],
            'assistant' => 'Find, count and read employees, add or update them, archive them, and list or add certifications. Pay, government ID numbers, bank details, home addresses and birth dates are never discussed in chat.',
            'keywords' => ['employee', 'staff', 'people', '201', 'profile', 'record', 'headcount', 'directory', 'certification', 'document'],
        ],
        'employee-access' => [
            'title' => 'App access', 'menu' => 'Workforce → Employees', 'path' => '/employees/access', 'any' => ['employees.invite'],
            'about' => 'Who can sign in as which employee: invitations sent to employees, requests from people who joined with the company code, and the company join code itself.',
            'tasks' => ['Invite an employee to the app', 'Approve or decline a join request, linking it to a roster line', 'Turn joining by code on or off, or generate a new code'],
            'assistant' => 'List invitations and join requests, invite or revoke, approve or decline a request, and switch joining by code on or off or replace the code (it never shows the code itself).',
            'keywords' => ['invite', 'invitation', 'join', 'join code', 'join request', 'app access', 'mobile app', 'sign up'],
        ],
        'attendance' => [
            'title' => 'Attendance', 'menu' => 'Workforce', 'path' => '/attendance', 'any' => ['attendance.view'],
            'about' => 'Daily time records built from clock-ins: worked hours, lateness, undertime, overtime and night differential, judged by each person\'s schedule and the attendance policy. Overtime that needs approval waits for a sign-off.',
            'tasks' => ['Review and correct a day', 'Sign off a day, or every pending one', 'Re-apply the current schedules and policies to a period', 'Export time records'],
            'assistant' => 'Read attendance and exceptions, record punches, set roster entries, sign off days (one or all pending) and re-apply schedules and policies to a period.',
            'keywords' => ['attendance', 'dtr', 'time', 'clock', 'punch', 'late', 'overtime', 'absent', 'undertime', 'sign off', 'approve overtime'],
        ],
        'my-attendance' => [
            'title' => 'My attendance', 'menu' => 'Attendance (also the mobile app)', 'path' => '/attendance/me', 'any' => ['attendance.clock'],
            'about' => 'Your own time record, and clocking in and out.',
            'tasks' => ['Clock in and out', 'See your own days'],
            'assistant' => 'Read your own attendance.',
            'keywords' => ['clock in', 'clock out', 'my attendance', 'my time'],
        ],
        'leave' => [
            'title' => 'Leave Management', 'menu' => 'Workforce', 'path' => '/leave', 'any' => ['leave.view'],
            'about' => 'Leave requests and their review, and each person\'s balances by leave type and year (entitlement, used, pending, remaining).',
            'tasks' => ['File a leave request', 'Approve or reject requests', 'Set yearly entitlements on the Balances tab'],
            'assistant' => 'Find, file, review and cancel requests; read balances (your own, or anyone\'s with leave access); set a yearly entitlement.',
            'keywords' => ['leave', 'vacation', 'sick', 'time off', 'balance', 'entitlement', 'credits', 'vl', 'sl'],
        ],
        'my-leave' => [
            'title' => 'Your own leave', 'menu' => 'The SYNAPSE mobile app, or the assistant', 'path' => '', 'any' => ['leave.request'],
            'about' => 'Filing and cancelling your own leave requests, and seeing your balances.',
            'tasks' => ['File a leave request', 'Cancel one still pending', 'Check how many days you have left'],
            'assistant' => 'File or cancel your own leave, and tell you your balances.',
            'keywords' => ['my leave', 'file leave', 'my balance', 'days left', 'time off'],
        ],
        'performance' => [
            'title' => 'Performance Management', 'menu' => 'Workforce', 'path' => '/performance', 'any' => ['performance.view'],
            'about' => 'Appraisals scored against the company\'s frameworks, review cycles, and AI insights on results.',
            'tasks' => ['Launch a review cycle', 'Rate, submit and acknowledge appraisals'],
            'assistant' => 'Find and read appraisals, open and rate them, submit and acknowledge, and launch cycles.',
            'keywords' => ['appraisal', 'review', 'evaluation', 'rating', 'kpi', 'cycle'],
        ],
        'training' => [
            'title' => 'Training & Development', 'menu' => 'Workforce', 'path' => '/training', 'any' => ['training.view'],
            'about' => 'Training programs and who is enrolled, with completion and outcomes.',
            'tasks' => ['Create a program', 'Enroll people and record completion'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['training', 'course', 'seminar', 'enroll', 'learning', 'development'],
        ],
        'awards' => [
            'title' => 'Awards & Recognition', 'menu' => 'Workforce', 'path' => '/awards', 'any' => ['awards.view'],
            'about' => 'Awards given to employees, nominees ranked for each award type, and citations.',
            'tasks' => ['Give an award', 'See who leads for an award'],
            'assistant' => 'Find awards, read nominees, give, update and remove awards.',
            'keywords' => ['award', 'recognition', 'employee of the month', 'nominee', 'citation'],
        ],
        'events' => [
            'title' => 'Events & Meetings', 'menu' => 'Workforce', 'path' => '/events', 'any' => ['events.view'],
            'about' => 'Company events and meetings with invitees, RSVPs, reminders and calendar files.',
            'tasks' => ['Schedule an event and invite people', 'Send a reminder', 'Track responses'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['event', 'meeting', 'invite', 'rsvp', 'calendar', 'reminder'],
        ],
        'offboarding' => [
            'title' => 'Offboarding', 'menu' => 'Offboarding', 'path' => '/offboarding', 'any' => ['offboarding.view'],
            'about' => 'Exits: the reason and last day, and a clearance checklist signed off by departments.',
            'tasks' => ['Start an exit', 'Track and sign off clearance items', 'Complete, cancel or reopen an exit'],
            'assistant' => 'Everything on this screen; starting, completing, deleting and bulk sign-offs wait for your confirmation.',
            'keywords' => ['exit', 'resignation', 'separation', 'clearance', 'offboard', 'leaving'],
        ],
        'attrition' => [
            'title' => 'Attrition Risk', 'menu' => 'Analytics & AI', 'path' => '/analytics/attrition', 'any' => ['analytics.attrition.view'],
            'about' => 'A model\'s estimate of how likely each active employee is to resign, with the factors behind it.',
            'tasks' => ['Run a new assessment', 'Read why someone is at watch', 'Graduate to a model trained on your own records'],
            'assistant' => 'Summaries, who is at risk, one person\'s score and factors (never pay), running and deleting assessments, and model graduation.',
            'keywords' => ['attrition', 'flight risk', 'turnover', 'resign', 'retention', 'leaving'],
        ],
        'forecast' => [
            'title' => 'Performance Forecast', 'menu' => 'Analytics & AI', 'path' => '/analytics/performance-forecast', 'any' => ['analytics.performance.view'],
            'about' => 'Each employee\'s next appraisal as a model projects it, with a range and band, checked against actual results.',
            'tasks' => ['Run a forecast', 'See who is forecast below target', 'See how accurate the last forecast was'],
            'assistant' => 'Summaries, who is in each band, one person\'s outlook, running and deleting forecasts, and model graduation.',
            'keywords' => ['forecast', 'outlook', 'projection', 'below target', 'next appraisal'],
        ],
        'promotion' => [
            'title' => 'Promotion Readiness', 'menu' => 'Analytics & AI', 'path' => '/analytics/promotion-readiness', 'any' => ['analytics.promotion.view'],
            'about' => 'How each employee\'s appraisal record compares with people who were promoted.',
            'tasks' => ['Run an assessment', 'See who is ready and who was left out'],
            'assistant' => 'Summaries, who is ready, one person\'s odds, who was left out and why, running and deleting assessments, and model graduation.',
            'keywords' => ['promotion', 'readiness', 'succession', 'promotable', 'career'],
        ],
        'reports' => [
            'title' => 'Reports', 'menu' => 'Analytics & AI', 'path' => '/reports', 'any' => ['employees.view', 'attendance.view', 'leave.view', 'recruitment.view', 'activity-logs.view'],
            'about' => 'Ready-made reports with filters, charts, AI analysis and export — each one only if you may see its data.',
            'tasks' => ['Run a report with filters', 'Export it', 'Read the AI analysis'],
            'assistant' => 'Run any report you may run and analyse its figures.',
            'keywords' => ['report', 'analytics', 'export', 'chart', 'figures', 'statistics'],
        ],
        'setup-wizard' => [
            'title' => 'Setup Guide', 'menu' => 'Company Setup', 'path' => '/setup/wizard', 'any' => ['setup.company.manage'],
            'about' => 'The guided walk-through that sets a company up, step by step, from its profile to its exit checklists.',
            'tasks' => ['Finish or revisit a setup step'],
            'assistant' => 'Say which setup steps are done, skipped or still open.',
            'keywords' => ['setup', 'wizard', 'getting started', 'onboard company', 'configure'],
        ],
        'company' => [
            'title' => 'Company Profile', 'menu' => 'Company Setup', 'path' => '/setup/company', 'any' => ['setup.company.view'],
            'about' => 'The company\'s name, contact details, registrations and time zone.',
            'tasks' => ['Update the company details', 'Set the time zone'],
            'assistant' => 'Read and update the profile and time zone.',
            'keywords' => ['company', 'profile', 'time zone', 'address', 'registration', 'tin', 'logo'],
        ],
        'departments' => [
            'title' => 'Departments', 'menu' => 'Company Setup', 'path' => '/setup/departments', 'any' => ['setup.departments.view'],
            'about' => 'The org structure: departments, their heads and nesting, and positions.',
            'tasks' => ['Add or rename departments and positions', 'Nest departments', 'Archive and restore'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['department', 'org chart', 'structure', 'position', 'job title', 'team'],
        ],
        'schedule' => [
            'title' => 'Work Schedule & Holidays', 'menu' => 'Company Setup', 'path' => '/setup/schedule', 'any' => ['setup.schedule.view'],
            'about' => 'Work schedules (days and hours) and the holiday calendar.',
            'tasks' => ['Create and edit schedules', 'Set the company default schedule', 'Add holidays'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['schedule', 'shift', 'working hours', 'holiday', 'calendar', 'rest day'],
        ],
        'roster' => [
            'title' => 'Shift Roster', 'menu' => 'Company Setup', 'path' => '/setup/roster', 'any' => ['setup.roster.view'],
            'about' => 'Who works which schedule, and one-off shift changes for a day.',
            'tasks' => ['Assign schedules to people', 'Override a single day'],
            'assistant' => 'Read shifts and set a one-off roster entry; bulk assignment stays on this screen.',
            'keywords' => ['roster', 'shift', 'assignment', 'override'],
        ],
        'attendance-policies' => [
            'title' => 'Attendance Policies', 'menu' => 'Company Setup', 'path' => '/setup/attendance-policies', 'any' => ['setup.attendance-policies.view'],
            'about' => 'How a day is judged: grace periods, rounding, overtime and night-differential rules, half days, and which clock-in methods count.',
            'tasks' => ['Create and edit policies', 'Set the default', 'Preview a worked example'],
            'assistant' => 'Read policies, work examples, create, update, set the default, archive and restore.',
            'keywords' => ['policy', 'grace', 'rounding', 'overtime rule', 'night differential', 'half day'],
        ],
        'locations' => [
            'title' => 'Locations', 'menu' => 'Company Setup', 'path' => '/setup/locations', 'any' => ['setup.locations.view'],
            'about' => 'Work sites, their geofence for clocking in, and who is based where.',
            'tasks' => ['Add and edit sites', 'Base people at a site'],
            'assistant' => 'Read, update, base and unbase people, archive and restore.',
            'keywords' => ['location', 'site', 'branch', 'office', 'geofence'],
        ],
        'leave-types' => [
            'title' => 'Leave Types', 'menu' => 'Company Setup', 'path' => '/setup/leave-types', 'any' => ['setup.leave-types.view'],
            'about' => 'The kinds of leave the company offers and their yearly defaults.',
            'tasks' => ['Add and edit leave types'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['leave type', 'vacation leave', 'sick leave', 'maternity', 'paternity'],
        ],
        'award-types' => [
            'title' => 'Award Types', 'menu' => 'Company Setup', 'path' => '/setup/award-types', 'any' => ['setup.award-types.view'],
            'about' => 'The awards the company gives and how often.',
            'tasks' => ['Add and edit award types'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['award type', 'recognition program'],
        ],
        'kpi' => [
            'title' => 'Performance Framework', 'menu' => 'Company Setup', 'path' => '/setup/kpi', 'any' => ['setup.kpi.view'],
            'about' => 'Appraisal frameworks, KPI criteria, rating scales and review cycles.',
            'tasks' => ['Build a framework', 'Define criteria and scales', 'Plan review cycles'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['framework', 'kpi', 'criteria', 'rating scale', 'review cycle', 'appraisal form'],
        ],
        'pipelines' => [
            'title' => 'Recruitment Pipelines', 'menu' => 'Company Setup', 'path' => '/setup/recruitment-pipelines', 'any' => ['recruitment.configure-pipelines'],
            'about' => 'The hiring stages postings run on.',
            'tasks' => ['Create pipelines and order their stages'],
            'assistant' => 'Everything on this screen; removing a stage or deleting a pipeline waits for your confirmation.',
            'keywords' => ['pipeline', 'hiring stages', 'hiring process'],
        ],
        'onboarding-programs' => [
            'title' => 'Onboarding Programs', 'menu' => 'Company Setup', 'path' => '/setup/onboarding', 'any' => ['onboarding.manage-programs'],
            'about' => 'The checklists new hires\' onboarding is seeded from.',
            'tasks' => ['Create programs and their tasks'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['onboarding program', 'onboarding template', 'checklist template'],
        ],
        'offboarding-programs' => [
            'title' => 'Offboarding Programs', 'menu' => 'Company Setup', 'path' => '/setup/offboarding', 'any' => ['offboarding.manage-programs'],
            'about' => 'The clearance checklists exits are seeded from.',
            'tasks' => ['Create templates and their sign-offs'],
            'assistant' => 'Everything on this screen.',
            'keywords' => ['clearance template', 'exit checklist', 'offboarding program'],
        ],
        'notifications' => [
            'title' => 'Notifications', 'menu' => 'System', 'path' => '/system/notifications', 'any' => [],
            'about' => 'Your notifications, your email and push preferences, and — for those allowed — sending announcements.',
            'tasks' => ['Read and clear notifications', 'Choose email and push delivery', 'Send an announcement to a person, a role or everyone'],
            'assistant' => 'Read your unread notifications, mark them read, change your delivery preferences, and send announcements (always after your confirmation).',
            'keywords' => ['notification', 'inbox', 'alert', 'announcement', 'broadcast', 'email', 'push'],
        ],
        'users' => [
            'title' => 'User Management', 'menu' => 'System', 'path' => '/system/users', 'any' => ['users.view'],
            'about' => 'Sign-in accounts: who can sign in, their roles, and their status.',
            'tasks' => ['Add, edit, deactivate and archive users', 'Reset a password', 'Import users from CSV'],
            'assistant' => 'Everything except passwords, photos and import/export.',
            'keywords' => ['user', 'account', 'login', 'sign in', 'password', 'deactivate'],
        ],
        'roles' => [
            'title' => 'Roles & Permissions', 'menu' => 'System', 'path' => '/system/roles', 'any' => ['roles.view'],
            'about' => 'What each role lets its holders do.',
            'tasks' => ['Create roles and choose their permissions'],
            'assistant' => 'Everything on this screen; nobody can grant a permission they do not hold.',
            'keywords' => ['role', 'permission', 'access', 'rights', 'hr manager'],
        ],
        'activity-logs' => [
            'title' => 'Activity Logs', 'menu' => 'System', 'path' => '/system/activity-logs', 'any' => ['activity-logs.view'],
            'about' => 'The audit trail: who did what, and when.',
            'tasks' => ['Search and export the trail'],
            'assistant' => 'Search and summarise the trail (read-only).',
            'keywords' => ['audit', 'log', 'history', 'who changed', 'trail'],
        ],
        'trash' => [
            'title' => 'Trash Bin', 'menu' => 'System', 'path' => '/system/trash', 'any' => ['users.view', 'employees.view', 'setup.departments.view', 'setup.leave-types.view'],
            'about' => 'Archived records, to restore or delete for good.',
            'tasks' => ['Restore a record', 'Delete one for good', 'Empty the bin'],
            'assistant' => 'List, restore or permanently delete one record at a time; emptying stays on the screen.',
            'keywords' => ['trash', 'archive', 'archived', 'restore', 'deleted', 'recycle'],
        ],
        'data-export' => [
            'title' => 'Data Export', 'menu' => 'System', 'path' => '/system/data-export', 'any' => ['data-export.view'],
            'about' => 'A copy of the company\'s records in one archive (CSV or JSON, optionally with the uploaded files), to keep or to move to another system. Only the person who prepared an archive can download it, for 7 days.',
            'tasks' => ['Choose the kinds of record to export and prepare an archive', 'Download your archive once you are notified it is ready', 'Delete an archive'],
            'assistant' => 'Point you to it; exports are prepared and downloaded on the screen, never in chat.',
            'keywords' => ['export', 'data export', 'backup', 'back up', 'download all', 'copy of our data', 'archive', 'data portability', 'leave synapse', 'migrate'],
        ],
        'settings' => [
            'title' => 'Your settings', 'menu' => 'Account menu → Settings', 'path' => '/settings/profile', 'any' => [],
            'about' => 'Your own profile, password, two-step sign-in and passkeys, and light or dark appearance.',
            'tasks' => ['Update your name and email (Profile)', 'Change your password, turn on two-step sign-in or add a passkey (Security, /settings/security)', 'Switch light or dark mode (Appearance, /settings/appearance)'],
            'assistant' => 'Point you to the right page — passwords are never handled in chat.',
            'keywords' => ['my profile', 'password', 'two-factor', '2fa', 'passkey', 'dark mode', 'appearance', 'security'],
        ],
        'workspaces' => [
            'title' => 'Workspaces', 'menu' => 'Account menu → Switch workspace', 'path' => '/workspaces', 'any' => [],
            'about' => 'The companies you belong to, and switching between them.',
            'tasks' => ['Switch to another company you work for'],
            'assistant' => 'List your workspaces and which one you are in; switching is done from the account menu.',
            'keywords' => ['workspace', 'company', 'switch', 'organisation', 'organization'],
        ],
        'assistant' => [
            'title' => 'The assistant', 'menu' => 'The sparkle button, bottom right', 'path' => '', 'any' => [],
            'about' => 'This chat. It answers from the workspace\'s live records and can act for you, only within your own permissions. Changes that matter wait for your Confirm, and everything it does is recorded in the audit trail as "via assistant".',
            'tasks' => ['Ask a question in plain words', 'Ask it to do something, then confirm'],
            'assistant' => 'It cannot see pay, government ID numbers, bank details, home addresses, birth dates or passwords.',
            'keywords' => ['assistant', 'chat', 'ai', 'copilot', 'what can you do'],
        ],
        'help-center' => [
            'title' => 'Help Center', 'menu' => 'Help (the question mark in the top bar) → Help Center', 'path' => '/help', 'any' => [],
            'about' => 'The user manual: a step-by-step article for every screen you can open, searchable, with "Help for this page" in the Help menu for the screen you are on.',
            'tasks' => ['Search for how to do something', 'Read the guide to a screen', 'Open the article for the page you are on'],
            'assistant' => 'Point you to the article that answers your question.',
            'keywords' => ['help center', 'manual', 'user manual', 'guide', 'documentation', 'docs', 'article', 'instructions', 'knowledge base'],
        ],
        'legal' => [
            'title' => 'Privacy Policy and Terms of Service', 'menu' => 'The links in the footer of every page', 'path' => '/privacy', 'any' => [],
            'about' => 'How personal information is handled in SYNAPSE and the rights people have over it (/privacy), and the terms of using the service (/terms).',
            'tasks' => ['Read the Privacy Policy', 'Read the Terms of Service'],
            'assistant' => 'Point you to them; privacy requests about your HR records go to your employer, or to the privacy contact in the policy.',
            'keywords' => ['privacy', 'privacy policy', 'terms', 'terms of service', 'personal data', 'data privacy', 'legal'],
        ],
        'tour' => [
            'title' => 'The product tour', 'menu' => 'Help (the question mark in the top bar) → Take the tour', 'path' => '', 'any' => [],
            'about' => 'A one-minute walk around the app — the sidebar sections you can open, notifications, your account menu and this assistant — shown once on your first visit, and any time again from the Help menu.',
            'tasks' => ['Replay the tour from the Help menu'],
            'assistant' => 'Point you to it; the tour is started from the Help menu, not from chat.',
            'keywords' => ['tour', 'tutorial', 'walkthrough', 'getting started', 'show me around', 'new here', 'first time'],
        ],
    ];

    /** What each company setup step is called. */
    public const SETUP_STEPS = [
        'company' => 'Company profile', 'departments' => 'Departments', 'attendance' => 'Attendance policies',
        'schedule' => 'Work schedules & holidays', 'leave-types' => 'Leave types', 'locations' => 'Locations',
        'roster' => 'Shift roster', 'recruitment' => 'Recruitment pipelines', 'onboarding' => 'Onboarding programs',
        'performance' => 'Performance framework', 'awards' => 'Award types', 'offboarding' => 'Offboarding programs',
    ];

    /**
     * The screens this user may open, keyed as above.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public static function for(User $user): Collection
    {
        return collect(self::SCREENS)->filter(
            fn (array $screen): bool => $screen['any'] === [] || collect($screen['any'])->contains(fn (string $p): bool => $user->can($p)),
        );
    }

    /**
     * The screens that best answer a question, best first — only ones this
     * user may open.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public static function search(User $user, string $question, int $limit = 3): Collection
    {
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($question)) ?: [])
            ->filter(fn (string $w): bool => mb_strlen($w) > 2 && ! in_array($w, self::STOP_WORDS, true))
            ->values();
        $text = ' '.Str::lower($question).' ';

        return self::for($user)
            ->map(function (array $screen) use ($words, $text): array {
                $score = 0;

                foreach ($screen['keywords'] as $keyword) {
                    $score += str_contains($text, ' '.$keyword) ? 5 : 0;
                }

                $haystack = Str::lower($screen['title'].' '.$screen['about'].' '.implode(' ', $screen['tasks']));

                foreach ($words as $word) {
                    $score += str_contains(Str::lower($screen['title']), $word) ? 3 : (str_contains($haystack, $word) ? 1 : 0);
                }

                return [...$screen, 'score' => $score];
            })
            ->filter(fn (array $screen): bool => $screen['score'] > 0)
            ->sortByDesc('score')
            ->take($limit);
    }

    /** Words too common to point at a screen. */
    private const STOP_WORDS = ['how', 'the', 'can', 'where', 'what', 'who', 'does', 'for', 'and', 'with', 'this', 'that', 'into', 'from', 'our', 'your', 'you', 'please', 'want', 'need', 'find', 'see', 'get', 'set', 'make', 'use', 'someone', 'somebody', 'there', 'here', 'page', 'screen'];
}

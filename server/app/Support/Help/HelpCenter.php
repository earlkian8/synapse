<?php

namespace App\Support\Help;

use App\Models\User;
use App\Services\Assistant\AssistantAccess;
use App\Support\SystemGuide;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Help Center (ADR 0062): SYNAPSE's user manual, one article per thing a
 * person does, grouped the way the sidebar is.
 *
 * This class is the catalogue — what each article is called, where it sits,
 * who may read it and which screens it explains. The words themselves are
 * Markdown files under `resources/help/<category>/<slug>.md`, written for the
 * reader and reviewed like any other change.
 *
 * Like the assistant's {@see SystemGuide}, the manual is always
 * read **for a user**: an article about a screen somebody cannot open is never
 * listed, searched, linked or served to them (it answers 404, as if it did not
 * exist), so the manual cannot be used to map the parts of the system they have
 * no access to. An article's `any` holds the same permissions as the screen it
 * explains; an empty list means everybody.
 *
 * Keeping it in step with the sidebar (`lib/app-navigation.ts`) and the routes
 * is part of adding a screen; HelpCenterTest checks every link and address.
 */
final class HelpCenter
{
    /**
     * In reading order. `icon` is a name the browser maps to its icon set.
     *
     * @var array<string, array{title: string, description: string, icon: string}>
     */
    public const CATEGORIES = [
        'getting-started' => [
            'title' => 'Getting started',
            'description' => 'Your first hour in SYNAPSE: how it is laid out, where each day starts, and getting your company and your people in.',
            'icon' => 'rocket',
        ],
        'your-account' => [
            'title' => 'Your account',
            'description' => 'Signing in, your profile, keeping your account secure, notifications, and working in more than one company.',
            'icon' => 'user-round',
        ],
        'talent-acquisition' => [
            'title' => 'Talent acquisition',
            'description' => 'Job postings, candidates, interviews and hiring, your public careers page, and onboarding new hires.',
            'icon' => 'briefcase-business',
        ],
        'workforce' => [
            'title' => 'Workforce',
            'description' => 'Employee records, attendance, leave, appraisals, training, recognition and events.',
            'icon' => 'users',
        ],
        'offboarding' => [
            'title' => 'Offboarding',
            'description' => 'Exits, from the notice to the last clearance sign-off.',
            'icon' => 'user-round-minus',
        ],
        'analytics' => [
            'title' => 'Analytics & AI',
            'description' => 'Predictions built from your own records, how to read them, and the reports behind your decisions.',
            'icon' => 'chart-column',
        ],
        'company-setup' => [
            'title' => 'Company setup',
            'description' => 'How your company runs: its structure, hours, attendance rules, leave, appraisals and the checklists every hire and exit starts from.',
            'icon' => 'settings-2',
        ],
        'administration' => [
            'title' => 'Administration',
            'description' => 'Accounts, roles and permissions, the audit trail, archived records, exporting your data and company-wide announcements.',
            'icon' => 'shield-check',
        ],
        'assistant' => [
            'title' => 'The assistant',
            'description' => 'Asking questions and getting work done in plain words, and what the assistant will and will not do.',
            'icon' => 'sparkles',
        ],
        'mobile-app' => [
            'title' => 'The mobile app',
            'description' => 'The employee companion: joining your company, clocking in from your phone, and your own leave.',
            'icon' => 'smartphone',
        ],
        'troubleshooting' => [
            'title' => 'Troubleshooting',
            'description' => 'Answers to the questions people ask most, and what to do when something does not look right.',
            'icon' => 'life-buoy',
        ],
    ];

    /**
     * Every article, in reading order within its category.
     *
     * - `any`: who may read it — the permissions of the screen it explains.
     * - `keywords`: the words people search with that the title may not use.
     * - `screens`: the pages it is the help for. `/x` is that page, `/x/*` the
     *   pages under it; "Help for this page" picks the most specific match.
     * - `related`: articles worth reading next (filtered for the reader).
     * - `featured`: offered under "Start here" on the Help Center's home.
     *
     * @var array<string, array{category: string, title: string, summary: string, any: list<string>, keywords: list<string>, screens?: list<string>, related?: list<string>, featured?: bool}>
     */
    public const ARTICLES = [
        // ── Getting started ──────────────────────────────────────────────────
        'welcome-to-synapse' => [
            'category' => 'getting-started', 'title' => 'Welcome to SYNAPSE',
            'summary' => 'What SYNAPSE does, how a company and its people fit together, and why you see what you see.',
            'any' => [], 'featured' => true,
            'keywords' => ['introduction', 'overview', 'what is synapse', 'new here', 'basics', 'roles'],
            'related' => ['finding-your-way-around', 'your-dashboard', 'roles-and-permissions'],
        ],
        'finding-your-way-around' => [
            'category' => 'getting-started', 'title' => 'Finding your way around',
            'summary' => 'The sidebar, the top bar, notifications, the Help menu and the assistant — what each part of the screen is for.',
            'any' => [], 'featured' => true,
            'keywords' => ['navigation', 'sidebar', 'menu', 'top bar', 'layout', 'tour', 'where is', 'dark mode'],
            'related' => ['welcome-to-synapse', 'your-dashboard', 'notifications'],
        ],
        'searching-synapse' => [
            'category' => 'getting-started', 'title' => 'Searching SYNAPSE',
            'summary' => 'Find a person, a record, a screen or a help article from any page with ⌘K or Ctrl+K, and open it straight away.',
            'any' => [],
            'keywords' => ['search', 'find', 'look up', 'command palette', 'ctrl k', 'cmd k', 'shortcut', 'quick open'],
            'related' => ['finding-your-way-around', 'meet-the-assistant'],
        ],
        'your-dashboard' => [
            'category' => 'getting-started', 'title' => 'Your dashboard',
            'summary' => 'Where each day starts: the numbers, the queue of things waiting on you, and what is coming up.',
            'any' => [],
            'keywords' => ['dashboard', 'home', 'overview', 'today', 'attention', 'needs my action'],
            'screens' => ['/dashboard'],
            'related' => ['finding-your-way-around', 'meet-the-assistant'],
        ],
        'setting-up-your-company' => [
            'category' => 'getting-started', 'title' => 'Setting up your company',
            'summary' => 'The Setup Guide walks a new company through every setup screen. What each step does, and picking up a step you skipped.',
            'any' => ['setup.company.manage'], 'featured' => true,
            'keywords' => ['setup guide', 'wizard', 'configure', 'new company', 'getting started', 'skipped step', 'onboard company'],
            'screens' => ['/setup/wizard', '/setup/wizard/*'],
            'related' => ['company-profile', 'inviting-your-people', 'attendance-policies'],
        ],
        'inviting-your-people' => [
            'category' => 'getting-started', 'title' => 'Inviting your people',
            'summary' => 'Give employees access to SYNAPSE by invitation or with your company join code, and approve the people waiting to join.',
            'any' => ['employees.invite'], 'featured' => true,
            'keywords' => ['invite', 'invitation', 'join code', 'join request', 'app access', 'sign up', 'onboard employees', 'access'],
            'screens' => ['/employees/access'],
            'related' => ['joining-your-company-on-mobile', 'employee-records', 'user-accounts'],
        ],

        // ── Your account ─────────────────────────────────────────────────────
        'signing-in' => [
            'category' => 'your-account', 'title' => 'Signing in and out',
            'summary' => 'Creating an account, confirming your email with a code, signing in with a password or a passkey, and resetting a forgotten password.',
            'any' => [],
            'keywords' => ['login', 'log in', 'sign in', 'register', 'create account', 'verify email', 'verification code', 'forgot password', 'reset password', 'logout', 'sign out', 'passkey'],
            'related' => ['password-and-security', 'working-in-more-than-one-company', 'faq'],
        ],
        'your-profile' => [
            'category' => 'your-account', 'title' => 'Your profile and appearance',
            'summary' => 'Change your name and email address, switch between light and dark mode, or delete your account.',
            'any' => [],
            'keywords' => ['profile', 'name', 'email', 'change email', 'dark mode', 'light mode', 'theme', 'appearance', 'delete account'],
            'screens' => ['/settings/profile', '/settings/appearance'],
            'related' => ['password-and-security', 'notifications'],
        ],
        'password-and-security' => [
            'category' => 'your-account', 'title' => 'Password, two-factor and passkeys',
            'summary' => 'Change your password, turn on two-factor authentication, keep your recovery codes, and sign in with a passkey.',
            'any' => [],
            'keywords' => ['password', 'change password', 'two-factor', '2fa', 'authenticator', 'recovery codes', 'passkey', 'security', 'totp'],
            'screens' => ['/settings/security'],
            'related' => ['signing-in', 'your-profile'],
        ],
        'notifications' => [
            'category' => 'your-account', 'title' => 'Notifications',
            'summary' => 'The bell, your notification centre, and choosing whether alerts also reach you by email or as desktop notifications.',
            'any' => [],
            'keywords' => ['notification', 'bell', 'alerts', 'inbox', 'email notifications', 'push', 'desktop notifications', 'unread', 'mark as read'],
            'screens' => ['/system/notifications'],
            'related' => ['sending-announcements', 'finding-your-way-around'],
        ],
        'working-in-more-than-one-company' => [
            'category' => 'your-account', 'title' => 'Working in more than one company',
            'summary' => 'One account, several workspaces: choosing where to work after you sign in, and switching between companies.',
            'any' => [],
            'keywords' => ['workspace', 'switch company', 'organisation', 'organization', 'multiple companies', 'workspace picker'],
            'screens' => ['/workspaces'],
            'related' => ['signing-in', 'joining-your-company-on-mobile'],
        ],

        'privacy-and-your-data' => [
            'category' => 'your-account', 'title' => 'Privacy and your data',
            'summary' => 'Who is responsible for your information, what you can ask for, and where the Privacy Policy and Terms of Service are.',
            'any' => [],
            'keywords' => ['privacy', 'privacy policy', 'terms', 'terms of service', 'data', 'personal information', 'data privacy act', 'delete my data', 'download my data', 'dpo', 'consent'],
            'related' => ['your-profile', 'assistant-privacy-and-confirmations'],
        ],

        // ── Talent acquisition ───────────────────────────────────────────────
        'job-postings' => [
            'category' => 'talent-acquisition', 'title' => 'Job postings',
            'summary' => 'Open a vacancy, choose its hiring process and screening criteria, publish it with a closing date, and follow it to filled.',
            'any' => ['recruitment.view'], 'featured' => true,
            'keywords' => ['job', 'posting', 'vacancy', 'opening', 'publish', 'closing date', 'screening questions', 'hiring'],
            'screens' => ['/recruitment'],
            'related' => ['candidates-and-the-pipeline', 'careers-page', 'recruitment-pipelines'],
        ],
        'candidates-and-the-pipeline' => [
            'category' => 'talent-acquisition', 'title' => 'Candidates and the pipeline',
            'summary' => 'Add candidates, move them through your stages on the table or the board, and read their fit score and AI insights.',
            'any' => ['recruitment.view'],
            'keywords' => ['candidate', 'applicant', 'pipeline', 'board', 'kanban', 'stage', 'fit score', 'ranking', 'ai insights', 'reject', 'advance'],
            'screens' => ['/recruitment/*'],
            'related' => ['interviews-and-hiring', 'job-postings'],
        ],
        'interviews-and-hiring' => [
            'category' => 'talent-acquisition', 'title' => 'Interviews and hiring',
            'summary' => 'Schedule interviews and record their outcome, then hire the candidate — which creates their employee record and starts their onboarding.',
            'any' => ['recruitment.view'],
            'keywords' => ['interview', 'schedule interview', 'hire', 'offer', 'new hire', 'outcome'],
            'related' => ['candidates-and-the-pipeline', 'onboarding-new-hires', 'employee-records'],
        ],
        'careers-page' => [
            'category' => 'talent-acquisition', 'title' => 'Your public careers page',
            'summary' => 'Every open posting gets a public page where anyone can apply. Sharing the link, and what applicants are asked.',
            'any' => ['recruitment.view'],
            'keywords' => ['careers', 'public link', 'apply', 'application form', 'job board', 'share posting'],
            'related' => ['job-postings', 'candidates-and-the-pipeline'],
        ],
        'onboarding-new-hires' => [
            'category' => 'talent-acquisition', 'title' => 'Onboarding new hires',
            'summary' => 'A checklist for every new hire: starting it, assigning and completing tasks, chasing what is overdue and closing it.',
            'any' => ['onboarding.view'],
            'keywords' => ['onboarding', 'new hire', 'checklist', 'orientation', 'first day', 'tasks', 'overdue', 'program'],
            'screens' => ['/onboarding', '/onboarding/*'],
            'related' => ['onboarding-programs', 'interviews-and-hiring'],
        ],

        // ── Workforce ────────────────────────────────────────────────────────
        'employee-records' => [
            'category' => 'workforce', 'title' => 'Employee records',
            'summary' => "Everyone's 201 file: adding and editing employees, documents and certifications, status changes, and archiving.",
            'any' => ['employees.view'], 'featured' => true,
            'keywords' => ['employee', '201 file', 'profile', 'directory', 'add employee', 'documents', 'certifications', 'status', 'archive', 'export', 'staff'],
            'screens' => ['/employees'],
            'related' => ['inviting-your-people', 'departments-and-positions', 'offboarding-an-employee'],
        ],
        'reviewing-attendance' => [
            'category' => 'workforce', 'title' => 'Reviewing attendance',
            'summary' => "Today's log and its exceptions, the weekly and monthly views, and what each status and flag means.",
            'any' => ['attendance.view'], 'featured' => true,
            'keywords' => ['attendance', 'dtr', 'time record', 'late', 'absent', 'exceptions', 'weekly', 'monthly', 'status', 'payroll summary'],
            'screens' => ['/attendance'],
            'related' => ['correcting-and-signing-off-attendance', 'attendance-policies', 'shift-roster'],
        ],
        'correcting-and-signing-off-attendance' => [
            'category' => 'workforce', 'title' => 'Correcting and signing off attendance',
            'summary' => 'Enter a missed punch, fix a wrong one, sign off overtime and flagged days, and re-apply the current rules to a period.',
            'any' => ['attendance.view'],
            'keywords' => ['correct attendance', 'manual entry', 'missed punch', 'forgot to clock out', 'sign off', 'approve overtime', 're-apply rules', 'edit punches'],
            'related' => ['reviewing-attendance', 'attendance-policies'],
        ],
        'clocking-in-and-out' => [
            'category' => 'workforce', 'title' => 'Clocking in and out',
            'summary' => 'Record your own time: clocking in, breaks and clocking out, and what happens when a punch is refused.',
            'any' => ['attendance.clock'], 'featured' => true,
            'keywords' => ['clock in', 'clock out', 'time in', 'time out', 'punch', 'break', 'my attendance', 'selfie', 'location'],
            'screens' => ['/attendance/me'],
            'related' => ['clocking-in-on-mobile', 'faq'],
        ],
        'managing-leave' => [
            'category' => 'workforce', 'title' => 'Managing leave',
            'summary' => 'Review and decide leave requests, file one on somebody’s behalf, and set yearly entitlements on the balances tab.',
            'any' => ['leave.view'], 'featured' => true,
            'keywords' => ['leave', 'approve leave', 'reject leave', 'leave request', 'balances', 'entitlement', 'vacation', 'sick leave', 'time off', 'credits'],
            'screens' => ['/leave', '/leave/balances'],
            'related' => ['leave-types', 'filing-your-own-leave'],
        ],
        'filing-your-own-leave' => [
            'category' => 'workforce', 'title' => 'Filing your own leave',
            'summary' => 'Ask for time off, see how many days you have left, and cancel a request that is still pending.',
            'any' => ['leave.request'],
            'keywords' => ['file leave', 'my leave', 'request time off', 'leave balance', 'days left', 'cancel leave', 'half day'],
            'related' => ['leave-on-mobile', 'managing-leave'],
        ],
        'performance-appraisals' => [
            'category' => 'workforce', 'title' => 'Performance appraisals',
            'summary' => 'Launch a review cycle, rate a scorecard against your framework, then submit and sign it off.',
            'any' => ['performance.view'],
            'keywords' => ['appraisal', 'performance review', 'evaluation', 'scorecard', 'rating', 'review cycle', 'kpi', 'sign off'],
            'screens' => ['/performance', '/performance/*'],
            'related' => ['performance-framework', 'performance-forecast', 'promotion-readiness'],
        ],
        'training-programs' => [
            'category' => 'workforce', 'title' => 'Training programs',
            'summary' => 'Create a program, enroll people, and record how each of them finished.',
            'any' => ['training.view'],
            'keywords' => ['training', 'course', 'seminar', 'enroll', 'learning', 'development', 'completion', 'score'],
            'screens' => ['/training', '/training/*'],
            'related' => ['employee-records'],
        ],
        'awards-and-recognition' => [
            'category' => 'workforce', 'title' => 'Awards and recognition',
            'summary' => 'Give an award, review the nominations colleagues make, read the AI shortlist, and run the rewards desk.',
            'any' => ['awards.view'],
            'keywords' => ['award', 'recognition', 'employee of the month', 'nominee', 'nominations', 'citation', 'approve nomination', 'shortlist', 'rewards', 'redemption', 'adjust points'],
            'screens' => ['/awards', '/awards/nominations', '/awards/shortlist', '/awards/rewards'],
            'related' => ['award-types', 'kudos-points-and-rewards'],
        ],
        'kudos-points-and-rewards' => [
            'category' => 'workforce', 'title' => 'Kudos, points and rewards',
            'summary' => 'Thank a colleague on the recognition wall, nominate them for an award, and spend the points you earn.',
            'any' => ['awards.participate'],
            'keywords' => ['kudos', 'thank a colleague', 'recognition wall', 'nominate', 'points', 'reward', 'redeem', 'my points'],
            'screens' => ['/awards/wall', '/awards/points', '/awards/my-nominations'],
            'related' => ['awards-and-recognition', 'leave-on-mobile'],
        ],
        'events-and-meetings' => [
            'category' => 'workforce', 'title' => 'Events and meetings',
            'summary' => 'Schedule an event once or on repeat, book a room, invite people, track replies and remind them automatically.',
            'any' => ['events.view'],
            'keywords' => ['event', 'meeting', 'invite', 'rsvp', 'calendar', 'reminder', 'ics', 'attendees', 'room', 'book a room', 'repeat', 'recurring'],
            'screens' => ['/events', '/events/rooms', '/events/*'],
            'related' => ['answering-event-invitations', 'notifications'],
        ],
        'answering-event-invitations' => [
            'category' => 'workforce', 'title' => 'Answering your invitations',
            'summary' => 'Say whether you are going, add an event to your calendar, or subscribe so every invitation appears there.',
            'any' => ['events.respond'],
            'keywords' => ['my invitations', 'rsvp', 'going', 'maybe', 'not going', 'subscribe', 'calendar feed', 'google calendar', 'outlook'],
            'screens' => ['/events/me'],
            'related' => ['events-and-meetings', 'notifications'],
        ],

        // ── Offboarding ──────────────────────────────────────────────────────
        'offboarding-an-employee' => [
            'category' => 'offboarding', 'title' => 'Offboarding an employee',
            'summary' => 'Start an exit, work through the clearance each department signs off, and complete it — which separates the employee.',
            'any' => ['offboarding.view'],
            'keywords' => ['offboarding', 'exit', 'resignation', 'termination', 'retirement', 'end of contract', 'clearance', 'separation', 'last day'],
            'screens' => ['/offboarding', '/offboarding/*'],
            'related' => ['offboarding-programs', 'employee-records'],
        ],

        // ── Analytics & AI ───────────────────────────────────────────────────
        'attrition-risk' => [
            'category' => 'analytics', 'title' => 'Attrition risk',
            'summary' => 'Run an assessment, read who may resign and why, and use it well: as a reason for a conversation, never a verdict.',
            'any' => ['analytics.attrition.view'],
            'keywords' => ['attrition', 'flight risk', 'turnover', 'resign', 'retention', 'risk score'],
            'screens' => ['/analytics/attrition'],
            'related' => ['model-graduation', 'reports'],
        ],
        'performance-forecast' => [
            'category' => 'analytics', 'title' => 'Performance forecast',
            'summary' => "Project each person's next appraisal with a likely range, and check how the last forecast did.",
            'any' => ['analytics.performance.view'],
            'keywords' => ['forecast', 'prediction', 'next appraisal', 'below target', 'projection', 'track record'],
            'screens' => ['/analytics/performance-forecast'],
            'related' => ['model-graduation', 'performance-appraisals'],
        ],
        'promotion-readiness' => [
            'category' => 'analytics', 'title' => 'Promotion readiness',
            'summary' => "Compare each person's appraisal record with the records of people who were promoted, and see who could not be assessed and why.",
            'any' => ['analytics.promotion.view'],
            'keywords' => ['promotion', 'readiness', 'succession', 'promotable', 'career'],
            'screens' => ['/analytics/promotion-readiness'],
            'related' => ['model-graduation', 'performance-appraisals'],
        ],
        'model-graduation' => [
            'category' => 'analytics', 'title' => 'Training a model on your own records',
            'summary' => 'Each prediction starts from a general model. How your company graduates to its own, and why nothing changes until you switch.',
            'any' => ['analytics.attrition.view', 'analytics.performance.view', 'analytics.promotion.view'],
            'keywords' => ['model graduation', 'own model', 'general model', 'train', 'requirements', 'switch model', 'machine learning'],
            'related' => ['attrition-risk', 'performance-forecast', 'promotion-readiness'],
        ],
        'reports' => [
            'category' => 'analytics', 'title' => 'Reports',
            'summary' => 'Run a ready-made report, filter it, export it, and ask for an AI read of what it shows.',
            'any' => ['employees.view', 'attendance.view', 'leave.view', 'recruitment.view', 'activity-logs.view'],
            'keywords' => ['report', 'export', 'csv', 'masterlist', 'headcount', 'turnover', 'chart', 'analysis', 'statistics'],
            'screens' => ['/reports'],
            'related' => ['attrition-risk', 'activity-logs'],
        ],

        // ── Company setup ────────────────────────────────────────────────────
        'company-profile' => [
            'category' => 'company-setup', 'title' => 'Company profile',
            'summary' => "Your company's name, logo, contact details, registrations and the time zone every day is judged on.",
            'any' => ['setup.company.view'],
            'keywords' => ['company', 'logo', 'time zone', 'timezone', 'address', 'registration', 'tin', 'legal name'],
            'screens' => ['/setup/company'],
            'related' => ['setting-up-your-company', 'inviting-your-people'],
        ],
        'departments-and-positions' => [
            'category' => 'company-setup', 'title' => 'Departments and positions',
            'summary' => 'Build your org structure: departments and their heads, nesting, and the positions in each.',
            'any' => ['setup.departments.view'],
            'keywords' => ['department', 'org chart', 'structure', 'position', 'job title', 'head', 'team'],
            'screens' => ['/setup/departments'],
            'related' => ['employee-records', 'work-schedules-and-holidays'],
        ],
        'work-schedules-and-holidays' => [
            'category' => 'company-setup', 'title' => 'Work schedules and holidays',
            'summary' => 'Shift templates — fixed, flexible, hours-only, rotating and split — the company default, and your holiday calendar.',
            'any' => ['setup.schedule.view'],
            'keywords' => ['schedule', 'shift', 'working hours', 'holiday', 'rest day', 'night shift', 'rotation', 'split shift', 'grace'],
            'screens' => ['/setup/schedule'],
            'related' => ['shift-roster', 'attendance-policies'],
        ],
        'shift-roster' => [
            'category' => 'company-setup', 'title' => 'Shift roster',
            'summary' => 'See who works which shift each day, assign people to a schedule from a date, and change a single day.',
            'any' => ['setup.roster.view'],
            'keywords' => ['roster', 'shift', 'assignment', 'override', 'day off', 'who works', 'plan'],
            'screens' => ['/setup/roster'],
            'related' => ['work-schedules-and-holidays', 'reviewing-attendance'],
        ],
        'attendance-policies' => [
            'category' => 'company-setup', 'title' => 'Attendance policies',
            'summary' => 'How a working day is judged: grace, lateness, overtime, breaks, night work and how punches may be made — from a preset.',
            'any' => ['setup.attendance-policies.view'],
            'keywords' => ['policy', 'grace period', 'overtime', 'night differential', 'rounding', 'half day', 'break', 'geofence', 'selfie', 'preset', 'labor code'],
            'screens' => ['/setup/attendance-policies'],
            'related' => ['work-schedules-and-holidays', 'work-locations', 'correcting-and-signing-off-attendance'],
        ],
        'work-locations' => [
            'category' => 'company-setup', 'title' => 'Work locations',
            'summary' => 'Draw your sites on the map with a fence for clocking in, and base people at them.',
            'any' => ['setup.locations.view'],
            'keywords' => ['location', 'site', 'branch', 'office', 'geofence', 'fence', 'map', 'radius'],
            'screens' => ['/setup/locations'],
            'related' => ['attendance-policies', 'clocking-in-and-out'],
        ],
        'leave-types' => [
            'category' => 'company-setup', 'title' => 'Leave types',
            'summary' => 'The kinds of leave you offer, how many days each gives a year, and whether it is paid, needs approval or allows half days.',
            'any' => ['setup.leave-types.view'],
            'keywords' => ['leave type', 'vacation leave', 'sick leave', 'entitlement', 'paid leave', 'half day', 'maternity', 'paternity'],
            'screens' => ['/setup/leave-types'],
            'related' => ['managing-leave'],
        ],
        'award-types' => [
            'category' => 'company-setup', 'title' => 'Award types',
            'summary' => 'The awards your company gives, retiring one, and why a type that was given cannot be deleted.',
            'any' => ['setup.award-types.view'],
            'keywords' => ['award type', 'recognition program', 'retire award'],
            'screens' => ['/setup/award-types'],
            'related' => ['awards-and-recognition'],
        ],
        'performance-framework' => [
            'category' => 'company-setup', 'title' => 'Performance framework',
            'summary' => 'Frameworks, criteria, rating scales and review cycles: everything an appraisal is scored against.',
            'any' => ['setup.kpi.view'],
            'keywords' => ['framework', 'kpi', 'criteria', 'rating scale', 'review cycle', 'appraisal form', 'weights', 'rating bands'],
            'screens' => ['/setup/kpi'],
            'related' => ['performance-appraisals'],
        ],
        'recruitment-pipelines' => [
            'category' => 'company-setup', 'title' => 'Recruitment pipelines',
            'summary' => 'Design the stages your hiring runs through, and choose the default for new postings.',
            'any' => ['recruitment.configure-pipelines'],
            'keywords' => ['pipeline', 'hiring stages', 'hiring process', 'stage', 'default pipeline'],
            'screens' => ['/setup/recruitment-pipelines'],
            'related' => ['job-postings', 'candidates-and-the-pipeline'],
        ],
        'onboarding-programs' => [
            'category' => 'company-setup', 'title' => 'Onboarding programs',
            'summary' => "The checklists new hires' onboarding is built from, and which hire gets which.",
            'any' => ['onboarding.manage-programs'],
            'keywords' => ['onboarding program', 'onboarding template', 'checklist template', 'due offset'],
            'screens' => ['/setup/onboarding'],
            'related' => ['onboarding-new-hires'],
        ],
        'offboarding-programs' => [
            'category' => 'company-setup', 'title' => 'Offboarding programs',
            'summary' => 'The clearance checklists exits start from, and which department signs off each item.',
            'any' => ['offboarding.manage-programs'],
            'keywords' => ['clearance template', 'exit checklist', 'offboarding program', 'clearance'],
            'screens' => ['/setup/offboarding'],
            'related' => ['offboarding-an-employee'],
        ],

        // ── Administration ───────────────────────────────────────────────────
        'user-accounts' => [
            'category' => 'administration', 'title' => 'User accounts',
            'summary' => 'Who can sign in to your workspace: adding, importing, deactivating and archiving accounts, and their roles.',
            'any' => ['users.view'],
            'keywords' => ['user', 'account', 'login', 'deactivate', 'import users', 'csv', 'reset password', 'assign role'],
            'screens' => ['/system/users'],
            'related' => ['roles-and-permissions', 'inviting-your-people', 'trash-bin'],
        ],
        'roles-and-permissions' => [
            'category' => 'administration', 'title' => 'Roles and permissions',
            'summary' => 'What the built-in roles can do, creating your own, and the rule that you can only give access you hold.',
            'any' => ['roles.view'],
            'keywords' => ['role', 'permission', 'access', 'rights', 'hr manager', 'department head', 'staff', 'custom role'],
            'screens' => ['/system/roles'],
            'related' => ['user-accounts'],
        ],
        'activity-logs' => [
            'category' => 'administration', 'title' => 'Activity logs',
            'summary' => 'The audit trail: who did what and when, searching it, and exporting it.',
            'any' => ['activity-logs.view'],
            'keywords' => ['audit', 'log', 'history', 'who changed', 'trail', 'activity'],
            'screens' => ['/system/activity-logs'],
            'related' => ['reports', 'user-accounts'],
        ],
        'trash-bin' => [
            'category' => 'administration', 'title' => 'Trash bin',
            'summary' => 'Archived accounts, employees, departments and leave types: restoring them, or deleting them for good.',
            'any' => ['users.view', 'employees.view', 'setup.departments.view', 'setup.leave-types.view'],
            'keywords' => ['trash', 'archive', 'archived', 'restore', 'deleted', 'recycle bin', 'permanently delete'],
            'screens' => ['/system/trash'],
            'related' => ['user-accounts', 'employee-records'],
        ],
        'data-export' => [
            'category' => 'administration', 'title' => 'Exporting your company\'s data',
            'summary' => 'Take a copy of your records — every kind you can see — as one archive, to keep or to move to another system.',
            'any' => ['data-export.view'],
            'keywords' => ['export', 'data export', 'backup', 'back up', 'download everything', 'archive', 'zip', 'csv', 'json', 'data portability', 'leaving'],
            'screens' => ['/system/data-export'],
            'related' => ['activity-logs', 'roles-and-permissions'],
        ],
        'sending-announcements' => [
            'category' => 'administration', 'title' => 'Sending announcements',
            'summary' => 'Send a notification to everyone, to everybody with a role, or to one person.',
            'any' => ['notifications.send'],
            'keywords' => ['announcement', 'broadcast', 'send notification', 'message everyone', 'compose'],
            'related' => ['notifications'],
        ],

        // ── The assistant ────────────────────────────────────────────────────
        'meet-the-assistant' => [
            'category' => 'assistant', 'title' => 'Meet the assistant',
            'summary' => 'Ask about your workspace in plain words, or ask it to do the work — within what your role allows.',
            'any' => AssistantAccess::PERMISSIONS, 'featured' => true,
            'keywords' => ['assistant', 'chat', 'ai', 'ask', 'copilot', 'shortcut', 'ctrl+j', 'what can you do', 'conversation'],
            'related' => ['assistant-privacy-and-confirmations', 'your-dashboard'],
        ],
        'assistant-privacy-and-confirmations' => [
            'category' => 'assistant', 'title' => 'What the assistant will and will not do',
            'summary' => 'Why it asks you to confirm, what it never reveals in chat, and how everything it does is recorded.',
            'any' => AssistantAccess::PERMISSIONS,
            'keywords' => ['confirm', 'privacy', 'salary', 'what can the assistant see', 'audit', 'via assistant', 'limits', 'quota'],
            'related' => ['meet-the-assistant', 'activity-logs'],
        ],

        // ── The mobile app ───────────────────────────────────────────────────
        'joining-your-company-on-mobile' => [
            'category' => 'mobile-app', 'title' => 'Joining your company from the app',
            'summary' => 'Create your own account in the SYNAPSE app, then connect it to your employer with an invitation or the company join code.',
            'any' => [],
            'keywords' => ['mobile', 'app', 'phone', 'join code', 'invitation code', 'register', 'join company', 'pending'],
            'related' => ['clocking-in-on-mobile', 'working-in-more-than-one-company'],
        ],
        'clocking-in-on-mobile' => [
            'category' => 'mobile-app', 'title' => 'Clocking in from your phone',
            'summary' => 'The Clock tab, your location and selfie, and punching when you have no signal.',
            'any' => [],
            'keywords' => ['mobile clock in', 'phone', 'offline', 'no signal', 'queued punch', 'gps', 'selfie', 'send now'],
            'related' => ['clocking-in-and-out', 'joining-your-company-on-mobile'],
        ],
        'leave-on-mobile' => [
            'category' => 'mobile-app', 'title' => 'Your leave, events, recognition and profile in the app',
            'summary' => 'File and cancel leave, answer invitations, send kudos and spend points, and see your profile and awards from your phone.',
            'any' => [],
            'keywords' => ['mobile leave', 'file leave on phone', 'balance', 'profile', 'awards', 'attendance calendar', 'events on phone', 'kudos on phone', 'points'],
            'related' => ['filing-your-own-leave', 'clocking-in-on-mobile', 'answering-event-invitations', 'kudos-points-and-rewards'],
        ],

        // ── Troubleshooting ──────────────────────────────────────────────────
        'faq' => [
            'category' => 'troubleshooting', 'title' => 'Frequently asked questions',
            'summary' => "A page you can't find, a code that didn't arrive, a time that looks wrong, a refused punch — and what to do.",
            'any' => [], 'featured' => false,
            'keywords' => ['faq', 'problem', 'not working', "can't see", 'missing page', 'no access', 'error', 'help', 'issue', 'wrong time'],
            'related' => ['welcome-to-synapse', 'signing-in'],
        ],
    ];

    /** Words too common to search with. */
    private const STOP_WORDS = ['how', 'the', 'can', 'where', 'what', 'who', 'does', 'for', 'and', 'with', 'this', 'that', 'into', 'from', 'our', 'your', 'you', 'please', 'want', 'need', 'find', 'see', 'get', 'set', 'make', 'use', 'someone', 'somebody', 'there', 'here', 'page', 'screen', 'are', 'not', 'why', 'when', 'its', 'has', 'have', 'was', 'were', 'will', 'would', 'should', 'about', 'any', 'all', 'one', 'do', 'to', 'of', 'in', 'on', 'is', 'it', 'an', 'or', 'my', 'me', 'be', 'at', 'by', 'as', 'if', 'so', 'up', 'we', 'us', 'am'];

    /** Reading speed for the "N min read" line. */
    private const WORDS_PER_MINUTE = 200;

    /**
     * The articles this user may read, keyed by slug, in reading order.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public static function articles(User $user): Collection
    {
        return collect(self::ARTICLES)
            ->filter(fn (array $article): bool => self::permits($user, $article))
            ->map(fn (array $article, string $slug): array => self::summaryOf($slug, $article));
    }

    /**
     * The categories with at least one article this user may read, each with
     * those articles — what the Help Center's home and its sidebar list.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function categories(User $user): Collection
    {
        $articles = self::articles($user)->groupBy('category');

        return collect(self::CATEGORIES)
            ->filter(fn (array $category, string $key): bool => $articles->has($key))
            ->map(fn (array $category, string $key): array => [
                'key' => $key,
                ...$category,
                'href' => self::categoryHref($key),
                'articles' => $articles->get($key)->values()->all(),
            ])
            ->values();
    }

    /**
     * One category for this user, or null when it has nothing they may read.
     *
     * @return array<string, mixed>|null
     */
    public static function category(User $user, string $key): ?array
    {
        return self::categories($user)->firstWhere('key', $key);
    }

    /**
     * One article for this user, in full: its body (with links to articles
     * they may not read reduced to plain words), reading time, related reading,
     * and the articles before and after it in reading order. Null when the
     * article does not exist, sits in another category, or is not theirs to
     * read — the three are indistinguishable from outside.
     *
     * @return array<string, mixed>|null
     */
    public static function article(User $user, string $category, string $slug): ?array
    {
        $visible = self::articles($user);
        $article = $visible->get($slug);

        if ($article === null || $article['category'] !== $category) {
            return null;
        }

        $order = $visible->keys()->values();
        $position = $order->search($slug);
        $neighbour = fn (int $at): ?array => $order->has($at) ? self::linkTo($visible->get($order->get($at))) : null;
        $body = self::readableBody($slug, $visible);

        return [
            ...$article,
            'body' => $body,
            'reading_minutes' => self::readingMinutes($body),
            'related' => collect(self::ARTICLES[$slug]['related'] ?? [])
                ->filter(fn (string $related): bool => $visible->has($related))
                ->map(fn (string $related): array => $visible->get($related))
                ->values()
                ->all(),
            'previous' => $neighbour($position - 1),
            'next' => $neighbour($position + 1),
        ];
    }

    /**
     * The articles that best answer a search, best first — only ones this user
     * may read. An article must match every word of the search somewhere; when
     * none does, those matching any word are offered instead and `partial` says
     * so.
     *
     * @return array{results: Collection<int, array<string, mixed>>, partial: bool, terms: list<string>}
     */
    public static function search(User $user, string $query, int $limit = 20): array
    {
        $phrase = Str::of($query)->lower()->squish()->toString();
        $terms = self::terms($phrase);

        if ($terms === []) {
            return ['results' => collect(), 'partial' => false, 'terms' => []];
        }

        $scored = self::articles($user)->map(function (array $article, string $slug) use ($terms, $phrase): array {
            $meta = self::ARTICLES[$slug];
            $title = Str::lower($article['title']);
            $summary = Str::lower($article['summary']);
            $keywords = Str::lower(implode(' | ', $meta['keywords']));
            $text = Str::lower(self::plainText(self::body($slug)));

            $score = 0;
            $matched = 0;

            foreach ($terms as $term) {
                $hit = match (true) {
                    str_contains($title, $term) => 6,
                    str_contains($keywords, $term) => 4,
                    str_contains($summary, $term) => 3,
                    str_contains($text, $term) => 1,
                    default => 0,
                };

                $score += $hit;
                $matched += $hit > 0 ? 1 : 0;
            }

            // The whole phrase, as typed, counts for more than its words apart.
            if (count($terms) > 1 || mb_strlen($phrase) > 3) {
                $score += str_contains($title, $phrase) ? 12 : 0;
                $score += collect($meta['keywords'])->contains(fn (string $k): bool => str_contains($phrase, Str::lower($k)) || Str::lower($k) === $phrase) ? 8 : 0;
                $score += str_contains($text, $phrase) ? 2 : 0;
            }

            return [
                ...$article,
                'score' => $score,
                'matched' => $matched,
                'snippet' => self::snippet(self::plainText(self::body($slug)), $terms, $article['summary']),
            ];
        });

        $complete = $scored->filter(fn (array $a): bool => $a['matched'] === count($terms));
        $partial = $complete->isEmpty();
        $results = ($partial ? $scored->filter(fn (array $a): bool => $a['matched'] > 0) : $complete)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $a): array => collect($a)->except(['score', 'matched'])->all())
            ->values();

        return ['results' => $results, 'partial' => $partial && $results->isNotEmpty(), 'terms' => $terms];
    }

    /**
     * The article that explains the page at this address, for this user — the
     * most specific of its `screens` that matches — or null.
     *
     * @return array<string, mixed>|null
     */
    public static function forPath(User $user, string $path): ?array
    {
        $path = '/'.trim((string) parse_url($path, PHP_URL_PATH), '/');
        $best = null;
        $bestRank = -1;

        foreach (self::articles($user) as $slug => $article) {
            foreach (self::ARTICLES[$slug]['screens'] ?? [] as $screen) {
                $rank = match (true) {
                    // The page itself: the most specific match there is.
                    $path === $screen => 3000 + strlen($screen),
                    // A page under one that names its children.
                    str_ends_with($screen, '/*') && str_starts_with($path, substr($screen, 0, -1)) => 2000 + strlen($screen),
                    // A page under one that does not — a weaker guess.
                    ! str_ends_with($screen, '/*') && str_starts_with($path, rtrim($screen, '/').'/') => 1000 + strlen($screen),
                    default => -1,
                };

                if ($rank > $bestRank) {
                    [$best, $bestRank] = [$article, $rank];
                }
            }
        }

        return $best;
    }

    /** The address of a category's page. */
    public static function categoryHref(string $category): string
    {
        return "/help/{$category}";
    }

    /** The address of an article. */
    public static function href(string $slug): string
    {
        return '/help/'.self::ARTICLES[$slug]['category'].'/'.$slug;
    }

    /** Where an article's words are kept. */
    public static function path(string $slug): string
    {
        return resource_path('help/'.self::ARTICLES[$slug]['category'].'/'.$slug.'.md');
    }

    /** An article's Markdown, as written. */
    public static function body(string $slug): string
    {
        return once(fn (): string => (string) @file_get_contents(self::path($slug)));
    }

    /**
     * @param  array<string, mixed>  $article
     */
    private static function permits(User $user, array $article): bool
    {
        return $article['any'] === [] || collect($article['any'])->contains(fn (string $permission): bool => $user->can($permission));
    }

    /**
     * What a list shows of an article.
     *
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    private static function summaryOf(string $slug, array $article): array
    {
        return [
            'slug' => $slug,
            'category' => $article['category'],
            'category_title' => self::CATEGORIES[$article['category']]['title'],
            'title' => $article['title'],
            'summary' => $article['summary'],
            'href' => self::href($slug),
            'featured' => $article['featured'] ?? false,
        ];
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array{slug: string, title: string, href: string, category_title: string}
     */
    private static function linkTo(array $article): array
    {
        return collect($article)->only(['slug', 'title', 'href', 'category_title'])->all();
    }

    /**
     * The body as this reader gets it: a link to an article they may not read
     * keeps its words and loses its address, so the manual never points at a
     * part of the system that is not theirs.
     *
     * @param  Collection<string, array<string, mixed>>  $visible
     */
    private static function readableBody(string $slug, Collection $visible): string
    {
        return (string) preg_replace_callback(
            '~\[([^\]]+)\]\(/help/([a-z0-9-]+)/([a-z0-9-]+)(#[a-z0-9-]+)?\)~',
            fn (array $m): string => $visible->has($m[3]) ? $m[0] : $m[1],
            self::body($slug),
        );
    }

    private static function readingMinutes(string $body): int
    {
        return max(1, (int) ceil(str_word_count(self::plainText($body)) / self::WORDS_PER_MINUTE));
    }

    /**
     * Markdown reduced to the words a person reads — for search and snippets.
     */
    private static function plainText(string $markdown): string
    {
        $text = preg_replace([
            '~^>\s*\[!(?:NOTE|TIP|IMPORTANT|WARNING)\]\s*$~mi', // callout markers
            '~!?\[([^\]]*)\]\([^)]*\)~',                       // links and images → their words
            '~`([^`]*)`~',                                     // inline code → its text
            '~^\s{0,3}(?:#{1,6}|>|[-*+]|\d+\.)\s+~m',          // heading, quote and list markers
            '~[*_]{1,3}([^*_]+)[*_]{1,3}~',                    // emphasis
            '~^\s*\|?[-:\s|]+\|?\s*$~m',                       // table rules
            '~\|~',                                            // table pipes
        ], ['', '$1', '$1', '', '$1', '', ' '], $markdown);

        return Str::squish((string) $text);
    }

    /**
     * The words of a search worth matching on.
     *
     * @return list<string>
     */
    private static function terms(string $phrase): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', $phrase) ?: [])
            ->filter(fn (string $w): bool => mb_strlen($w) > 1 && ! in_array($w, self::STOP_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A line of the article around the first place it says what was searched
     * for, or its summary when only the title or keywords matched.
     *
     * @param  list<string>  $terms
     */
    private static function snippet(string $text, array $terms, string $fallback): string
    {
        $lower = Str::lower($text);
        $at = collect($terms)
            ->map(fn (string $term): int|false => mb_strpos($lower, $term))
            ->filter(fn (int|false $position): bool => $position !== false)
            ->min();

        if ($at === null) {
            return $fallback;
        }

        $start = max(0, $at - 70);
        $excerpt = mb_substr($text, $start, 200);

        // Start and end on whole words.
        if ($start > 0) {
            $excerpt = '…'.ltrim((string) Str::after($excerpt, ' '));
        }

        if ($start + 200 < mb_strlen($text)) {
            $excerpt = Str::beforeLast($excerpt, ' ').'…';
        }

        return $excerpt;
    }
}

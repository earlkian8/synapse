import {
    Award,
    BarChart3,
    Bell,
    BriefcaseBusiness,
    Building,
    CalendarCheck,
    CalendarClock,
    CalendarCog,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    Compass,
    DatabaseBackup,
    Gauge,
    GraduationCap,
    LayoutGrid,
    LineChart,
    ListChecks,
    Mail,
    MapPinned,
    Medal,
    Network,
    Scale,
    ScrollText,
    ShieldCheck,
    Target,
    Trash2,
    TrendingDown,
    Trophy,
    UserCog,
    UserRoundCheck,
    UserRoundMinus,
    Users,
    Workflow,
} from 'lucide-react';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

/**
 * The sidebar, section by section — read by the sidebar itself and by the
 * first-run tour (ADR 0060), so the tour only ever describes what the person
 * can actually see. Keep the assistant's `SystemGuide` in step when a screen is
 * added here.
 */
export type AppNavItem = NavItem & {
    permission?: string;
    permissionAny?: string[];
    /**
     * One line on what the screen is for, shown by the tour. Left out for a
     * link with no screen behind it yet, so the tour never describes one.
     */
    summary?: string;
};

export type AppNavSection =
    | 'main'
    | 'talent'
    | 'workforce'
    | 'offboarding'
    | 'analytics'
    | 'setup'
    | 'system';

export type AppNavGroup = {
    key: AppNavSection;
    label: string;
    items: AppNavItem[];
};

export const APP_NAVIGATION: AppNavGroup[] = [
    {
        key: 'main',
        label: 'Main',
        items: [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
                summary: 'Your day at a glance',
            },
        ],
    },
    {
        key: 'talent',
        label: 'Talent Acquisition',
        items: [
            {
                title: 'Recruitment',
                href: '/recruitment',
                icon: BriefcaseBusiness,
                permission: 'recruitment.view',
                summary: 'Job postings, applicants and interviews',
            },
            {
                title: 'Onboarding',
                href: '/onboarding',
                icon: UserRoundCheck,
                permission: 'onboarding.view',
                summary: 'A checklist for every new hire',
            },
        ],
    },
    {
        key: 'workforce',
        label: 'Workforce',
        items: [
            {
                title: 'Employees',
                href: '/employees',
                icon: Users,
                permission: 'employees.view',
                summary: "Everyone's 201 file, and app access",
            },
            // { title: 'Departments', href: '/departments', icon: Building2 },
            {
                title: 'Attendance',
                href: '/attendance',
                icon: CalendarCheck,
                permission: 'attendance.view',
                summary: 'Daily time records and sign-offs',
            },
            // {
            //     title: 'My Attendance',
            //     href: '/attendance/me',
            //     icon: Clock,
            //     permission: 'attendance.clock',
            // },
            {
                title: 'Leave Management',
                href: '/leave',
                icon: CalendarDays,
                permission: 'leave.view',
                summary: 'Requests, approvals and balances',
            },
            {
                title: 'Performance Management',
                href: '/performance',
                icon: Gauge,
                permission: 'performance.view',
                summary: 'Appraisals and review cycles',
            },
            {
                title: 'Training & Development',
                href: '/training',
                icon: GraduationCap,
                permission: 'training.view',
                summary: 'Programs and who is enrolled',
            },
            {
                title: 'Awards & Recognition',
                href: '/awards',
                icon: Award,
                permission: 'awards.view',
                summary: 'Awards, nominees and citations',
            },
            {
                title: 'Events & Meetings',
                href: '/events',
                icon: CalendarClock,
                permission: 'events.view',
                summary: 'Invitations, RSVPs and reminders',
            },
        ],
    },
    {
        key: 'offboarding',
        label: 'Offboarding',
        items: [
            {
                title: 'Offboarding',
                href: '/offboarding',
                icon: UserRoundMinus,
                permission: 'offboarding.view',
                summary: 'Exits and their clearance checklists',
            },
        ],
    },
    {
        key: 'analytics',
        label: 'Analytics & AI',
        items: [
            {
                title: 'Attrition Risk',
                href: '/analytics/attrition',
                icon: TrendingDown,
                permission: 'analytics.attrition.view',
                summary: 'Who may resign, and why',
            },
            {
                title: 'Performance Forecast',
                href: '/analytics/performance-forecast',
                icon: LineChart,
                permission: 'analytics.performance.view',
                summary: 'Next appraisals, projected',
            },
            {
                title: 'Promotion Readiness',
                href: '/analytics/promotion-readiness',
                icon: Medal,
                permission: 'analytics.promotion.view',
                summary: 'Records compared with past promotions',
            },
            {
                title: 'Reports',
                href: '/reports',
                icon: BarChart3,
                // Visible to anyone who can run at least one report.
                permissionAny: [
                    'employees.view',
                    'attendance.view',
                    'leave.view',
                    'recruitment.view',
                    'activity-logs.view',
                ],
                summary: 'Ready-made reports to filter and export',
            },
        ],
    },
    {
        key: 'setup',
        label: 'Company Setup',
        items: [
            {
                // The guided walk-through a new company is taken through before its
                // dashboard. It stays listed afterwards so a step that was skipped on
                // day one can be picked up later.
                title: 'Setup Guide',
                href: '/setup/wizard',
                icon: Compass,
                permission: 'setup.company.manage',
                summary: 'Pick up any setup step you skipped',
            },
            {
                title: 'Company Profile',
                href: '/setup/company',
                icon: Building,
                permission: 'setup.company.view',
                summary: 'Name, registrations and time zone',
            },
            {
                title: 'Departments',
                href: '/setup/departments',
                icon: Network,
                permission: 'setup.departments.view',
                summary: 'The org structure and positions',
            },
            {
                title: 'Work Schedule & Holidays',
                href: '/setup/schedule',
                icon: CalendarClock,
                permission: 'setup.schedule.view',
                summary: 'Working hours and the holiday calendar',
            },
            {
                // Who is due to work what, day by day (ADR 0037) — the plan each day
                // of attendance is judged against.
                title: 'Shift Roster',
                href: '/setup/roster',
                icon: CalendarCog,
                permission: 'setup.roster.view',
                summary: 'Who works which shift, day by day',
            },
            {
                title: 'Attendance Policies',
                href: '/setup/attendance-policies',
                icon: Scale,
                permission: 'setup.attendance-policies.view',
                summary: 'How a working day is judged',
            },
            {
                // Sites and their fences (ADR 0040).
                title: 'Locations',
                href: '/setup/locations',
                icon: MapPinned,
                permission: 'setup.locations.view',
                summary: 'Work sites and their clock-in fences',
            },
            {
                title: 'Leave Types',
                href: '/setup/leave-types',
                icon: CalendarRange,
                permission: 'setup.leave-types.view',
                summary: 'The kinds of leave you offer',
            },
            {
                title: 'Award Types',
                href: '/setup/award-types',
                icon: Trophy,
                permission: 'setup.award-types.view',
                summary: 'The awards you give',
            },
            {
                title: 'Performance Framework',
                href: '/setup/kpi',
                icon: Target,
                permission: 'setup.kpi.view',
                summary: 'Criteria, rating scales and cycles',
            },
            {
                title: 'Recruitment Pipelines',
                href: '/setup/recruitment-pipelines',
                icon: Workflow,
                permission: 'recruitment.configure-pipelines',
                summary: 'The stages hiring runs through',
            },
            {
                title: 'Onboarding Programs',
                href: '/setup/onboarding',
                icon: ListChecks,
                permission: 'onboarding.manage-programs',
                summary: 'Checklists new hires start from',
            },
            {
                title: 'Offboarding Programs',
                href: '/setup/offboarding',
                icon: ClipboardList,
                permission: 'offboarding.manage-programs',
                summary: 'Clearance checklists for exits',
            },
            {
                title: 'Email & Notifications',
                href: '/setup/notifications',
                icon: Mail,
            },
        ],
    },
    {
        key: 'system',
        label: 'System',
        items: [
            {
                title: 'Notifications',
                href: '/system/notifications',
                icon: Bell,
                summary: 'Everything sent to you, and how',
            },
            {
                title: 'User Management',
                href: '/system/users',
                icon: UserCog,
                permission: 'users.view',
                summary: 'Who can sign in, and as what',
            },
            {
                title: 'Roles & Permissions',
                href: '/system/roles',
                icon: ShieldCheck,
                permission: 'roles.view',
                summary: 'What each role lets people do',
            },
            {
                title: 'Activity Logs',
                href: '/system/activity-logs',
                icon: ScrollText,
                permission: 'activity-logs.view',
                summary: 'Who did what, and when',
            },
            {
                title: 'Trash Bin',
                href: '/system/trash',
                icon: Trash2,
                // Visible to anyone who can view at least one archivable record type.
                permissionAny: [
                    'users.view',
                    'employees.view',
                    'setup.departments.view',
                    'setup.leave-types.view',
                ],
                summary: 'Archived records, to restore',
            },
            {
                title: 'Data Backup & Export',
                href: '/system/backup',
                icon: DatabaseBackup,
            },
        ],
    },
];

import {
    Award,
    BarChart3,
    Bell,
    BriefcaseBusiness,
    Building,
    CalendarCheck,
    CalendarClock,
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
    MapPinned,
    Medal,
    Network,
    Route,
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
    /** One line on what the screen is for, shown by the tour. */
    summary: string;
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
                // One entry for the module (ADR 0091): HR lands on the postings,
                // somebody who only interviews on their own interviews.
                title: 'Recruitment',
                href: '/recruitment',
                icon: BriefcaseBusiness,
                permissionAny: ['recruitment.view', 'recruitment.interview'],
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
                // One entry for the module (ADR 0090): the time records, the
                // shift roster they are judged against, and the kiosks (ADR
                // 0092). Somebody who may only see the roster, or only look after
                // the kiosks, lands on theirs.
                title: 'Attendance',
                href: '/attendance',
                icon: CalendarCheck,
                permissionAny: [
                    'attendance.view',
                    'attendance.roster.view',
                    'attendance.kiosks.manage',
                ],
                summary: 'Time records, sign-offs, the shift roster and kiosks',
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
                // One entry for everybody (ADR 0074): HR lands on the programs,
                // someone who only takes part on their own training.
                title: 'Training & Development',
                href: '/training',
                icon: GraduationCap,
                permissionAny: ['training.view', 'training.participate'],
                summary: 'Programs, sessions, certificates and budget',
            },
            {
                // One entry for everybody (ADR 0071): HR lands on the awards
                // register, someone who only takes part on the wall.
                title: 'Awards & Recognition',
                href: '/awards',
                icon: Award,
                permissionAny: ['awards.view', 'awards.participate'],
                summary: 'Kudos, awards, nominations and points',
            },
            {
                // One entry for everybody (ADR 0070): HR lands on every event,
                // someone who only answers invitations on their own.
                title: 'Events & Meetings',
                href: '/events',
                icon: CalendarClock,
                permissionAny: ['events.view', 'events.respond'],
                summary: 'Events, rooms and your invitations',
            },
        ],
    },
    {
        key: 'offboarding',
        label: 'Offboarding',
        items: [
            {
                // One entry for everybody (ADR 0076): HR lands on every exit,
                // someone who only takes part on their own resignation.
                title: 'Offboarding',
                href: '/offboarding',
                icon: UserRoundMinus,
                permissionAny: ['offboarding.view', 'offboarding.resign'],
                summary: 'Exits, resignations and exit interviews',
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
                // A copy of the workspace's records (ADR 0066). The database itself
                // is backed up by its host, so this screen exports rather than backs up.
                title: 'Data Export',
                href: '/system/data-export',
                icon: DatabaseBackup,
                permission: 'data-export.view',
                summary: "A copy of your company's records",
            },
            {
                // Who takes the tour, and where they stop (ADR 0080).
                title: 'Product Tour',
                href: '/system/product-tour',
                icon: Route,
                permission: 'product-tour.view',
                summary: 'Who takes the tour, and where they stop',
            },
        ],
    },
];

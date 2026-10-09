import {
    BarChart3,
    Bell,
    BriefcaseBusiness,
    Building2,
    CalendarCheck,
    CalendarDays,
    CircleHelp,
    Clock3,
    Compass,
    LayoutGrid,
    Settings2,
    ShieldCheck,
    Sparkles,
    UserPlus,
    UserRound,
    UserRoundMinus,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { AppNavGroup, AppNavSection } from '@/lib/app-navigation';
import type { TourNextStep, TourStop } from './types';

/** What the tour's wording depends on — all of it read from the page. */
export type TourContext = {
    firstName: string;
    organizationName: string;
    workspaceCount: number;
    /** The sidebar sections the person can see ({@link useAppNavigation}). */
    groups: AppNavGroup[];
    can: (permission: string) => boolean;
    canAny: (...permissions: string[]) => boolean;
};

/** The permissions that fill the dashboard with management panels. */
const OVERVIEW_PERMISSIONS = [
    'employees.view',
    'attendance.view',
    'leave.manage',
    'recruitment.view',
    'events.view',
    'activity-logs.view',
];

type SectionCopy = {
    title: string;
    icon: LucideIcon;
    body: (context: TourContext, group: AppNavGroup) => string;
};

/**
 * What the tour says about each sidebar section. The section's screens are
 * listed under it from the navigation itself, so this only has to say what the
 * section is for — never which screens are in it.
 */
const SECTIONS: Record<Exclude<AppNavSection, 'main'>, SectionCopy> = {
    talent: {
        title: 'Talent acquisition',
        icon: BriefcaseBusiness,
        body: () =>
            'Everything that brings people in, from the first job posting to their first day.',
    },
    workforce: {
        title: 'Your workforce',
        icon: Users,
        body: () => 'The day-to-day of everyone who already works here.',
    },
    offboarding: {
        title: 'Offboarding',
        icon: UserRoundMinus,
        body: () =>
            'When someone leaves: the reason, the last day, and a clearance each department signs off.',
    },
    analytics: {
        title: 'Analytics & AI',
        icon: BarChart3,
        body: () =>
            'Predictions and reports built from your own records, to show you where to look first.',
    },
    setup: {
        title: 'Company setup',
        icon: Settings2,
        body: ({ can }) =>
            can('setup.company.manage')
                ? 'How your company runs: its structure, hours, leave and policies. Anything you skipped during setup is waiting in the Setup Guide.'
                : 'How your company runs: its structure, hours, leave and policies.',
    },
    system: {
        title: 'System',
        icon: ShieldCheck,
        body: (_context, group) =>
            group.items.some((item) => item.href !== '/system/notifications')
                ? 'Your notifications, and the accounts, roles and records that keep the workspace in order.'
                : 'Everything that has been sent to you, and how you would like to hear about it.',
    },
};

/** The sidebar's stops share a placement: beside the sidebar, top-aligned. */
const SIDEBAR_PLACEMENT = {
    side: 'right',
    align: 'start',
    padding: 4,
    radius: 12,
} as const;

/** The top bar's buttons: below them, lined up with their right edge. */
const TOP_BAR_PLACEMENT = {
    side: 'bottom',
    align: 'end',
    padding: 4,
} as const;

/**
 * Every stop the tour could make for this person, in order. The ones whose
 * target is not on screen when the tour starts are passed over then — the
 * sidebar on a phone, or the assistant for somebody it is not offered to.
 */
export function buildTourStops(context: TourContext): TourStop[] {
    const { organizationName, workspaceCount, groups, can, canAny } = context;
    const stops: TourStop[] = [];

    stops.push({
        kind: 'stop',
        id: 'workspace',
        target: 'workspace',
        label: 'Workspace',
        icon: Building2,
        ...(workspaceCount > 1
            ? {
                  title: 'Your workspaces',
                  body: `You belong to ${workspaceCount} companies, and this is ${organizationName}. Switch between them here — everything you see follows the one you are in.`,
              }
            : {
                  title: 'Your workspace',
                  body: `Everything here belongs to ${organizationName}: its people, their records and its settings. You only ever see what your role allows.`,
              }),
        ...SIDEBAR_PLACEMENT,
        radius: 10,
    });

    stops.push({
        kind: 'stop',
        id: 'nav-main',
        target: 'nav-main',
        label: 'Dashboard',
        icon: LayoutGrid,
        title: 'Your dashboard',
        body: canAny(...OVERVIEW_PERMISSIONS)
            ? "Where each day starts: who's in, who's away, what's waiting on you and what's coming up — only the parts your role covers."
            : 'Where each day starts, with shortcuts to what you use most: your time record, your leave and your profile.',
        ...SIDEBAR_PLACEMENT,
    });

    for (const group of groups) {
        if (group.key === 'main') {
            continue;
        }

        const copy = SECTIONS[group.key];

        stops.push({
            kind: 'stop',
            id: `nav-${group.key}`,
            target: `nav-${group.key}`,
            label: group.label,
            icon: copy.icon,
            title: copy.title,
            body: copy.body(context, group),
            items: group.items,
            ...SIDEBAR_PLACEMENT,
        });
    }

    stops.push({
        kind: 'stop',
        id: 'notifications',
        target: 'notifications',
        label: 'Notifications',
        icon: Bell,
        title: 'Notifications',
        body: 'Approvals, reminders and announcements land here. Choose what reaches you by email or push under System → Notifications.',
        ...TOP_BAR_PLACEMENT,
        radius: 10,
    });

    stops.push({
        kind: 'stop',
        id: 'help',
        target: 'help',
        label: 'Help',
        icon: CircleHelp,
        title: 'Help, whenever you need it',
        body: can('setup.company.manage')
            ? 'The Help Center has a guide for every screen you can use — including the one you are on. Replay this tour, or reopen the Setup Guide to finish a step you skipped.'
            : 'The Help Center has a guide for every screen you can use — including the one you are on. You can replay this tour from here too.',
        ...TOP_BAR_PLACEMENT,
        radius: 10,
    });

    stops.push({
        kind: 'stop',
        id: 'account',
        target: 'account',
        label: 'Your account',
        icon: UserRound,
        title: 'Your account',
        body: 'Your profile, password, two-step sign-in and passkeys, and light or dark mode — all under Settings.',
        ...TOP_BAR_PLACEMENT,
        radius: 9999,
    });

    stops.push({
        kind: 'stop',
        id: 'assistant',
        target: 'assistant',
        label: 'Assistant',
        icon: Sparkles,
        title: 'Meet your assistant',
        body: 'Ask in plain words — "what needs my attention today?" — and it answers from your live records, or does the work for you within what your role allows. Changes that matter wait for your Confirm. It opens beside the page; ⌘J or Ctrl+J opens it from anywhere.',
        ...TOP_BAR_PLACEMENT,
        radius: 10,
    });

    return stops;
}

/**
 * Where to go once the tour is over: the first three of these the person can
 * do, most useful first, so an owner is pointed at bringing people in and an
 * employee at their own records.
 */
export function buildNextSteps(
    can: (permission: string) => boolean,
): TourNextStep[] {
    const candidates: (TourNextStep | false)[] = [
        can('employees.invite') && {
            title: 'Bring your people in',
            description: 'Invite them by email or share your join code.',
            href: '/employees/access',
            icon: UserPlus,
        },
        can('setup.company.manage') && {
            title: 'Finish setting up',
            description: 'Pick up any setup step you skipped.',
            href: '/setup/wizard',
            icon: Compass,
        },
        can('recruitment.view') && {
            title: 'Start hiring',
            description: 'Open a job posting and follow its applicants.',
            href: '/recruitment',
            icon: BriefcaseBusiness,
        },
        can('leave.view') &&
            can('leave.manage') && {
                title: 'Review leave requests',
                description: 'Approve or decline what is waiting on you.',
                href: '/leave',
                icon: CalendarDays,
            },
        can('attendance.view') && {
            title: "Check today's attendance",
            description: "See who's in, who's late and who's away.",
            href: '/attendance',
            icon: CalendarCheck,
        },
        can('attendance.clock') && {
            title: 'Your time record',
            description: 'Clock in and out, and see your own days.',
            href: '/attendance/me',
            icon: Clock3,
        },
        {
            title: 'Complete your profile',
            description: 'Add a photo and check your details.',
            href: '/settings/profile',
            icon: UserRound,
        },
    ];

    return candidates
        .filter((step): step is TourNextStep => step !== false)
        .slice(0, 3);
}

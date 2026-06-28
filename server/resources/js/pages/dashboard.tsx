import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, CalendarPlus, Clock3, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { DashboardHero } from '@/features/dashboard/components/dashboard-hero';
import {
    ActivityPanel,
    AttendancePanel,
    AttentionPanel,
    DepartmentsPanel,
    EventsPanel,
    RecruitmentPanel,
    SectionCard,
    WorkforcePanel,
} from '@/features/dashboard/components/panels';
import type { DashboardProps } from '@/features/dashboard/types';
import { usePermissions } from '@/hooks/use-permissions';

export default function Dashboard() {
    const props = usePage<DashboardProps>().props;
    const { workforce, attendance, recruitment, attention, events, activity } =
        props;

    // Panels are built in priority order; only those the viewer is permitted to see
    // (non-null) are rendered. The masonry packs whatever remains.
    const panels: ReactNode[] = [];

    panels.push(<AttentionPanel key="attention" items={attention} />);

    if (workforce) {
        panels.push(<WorkforcePanel key="workforce" workforce={workforce} />);
    }

    if (attendance) {
        panels.push(
            <AttendancePanel key="attendance" attendance={attendance} />,
        );
    }

    if (recruitment) {
        panels.push(
            <RecruitmentPanel key="recruitment" recruitment={recruitment} />,
        );
    }

    if (workforce) {
        panels.push(
            <DepartmentsPanel key="departments" workforce={workforce} />,
        );
    }

    if (events) {
        panels.push(<EventsPanel key="events" events={events} />);
    }

    if (activity) {
        panels.push(<ActivityPanel key="activity" activity={activity} />);
    }

    // A regular employee sees none of the management panels (attention is then empty);
    // give them a small, useful starting point instead of a barren grid.
    const hasOverview =
        workforce ||
        attendance ||
        recruitment ||
        events ||
        activity ||
        attention.length > 0;

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <DashboardHero {...props} />

                {hasOverview ? (
                    <div className="gap-4 [column-fill:balance] sm:columns-2 xl:columns-3 [&>*]:mb-4 [&>*]:break-inside-avoid">
                        {panels.map((panel, index) => (
                            <div
                                key={index}
                                className="dash-rise"
                                style={{
                                    animationDelay: `${Math.min(index, 6) * 60}ms`,
                                }}
                            >
                                {panel}
                            </div>
                        ))}
                    </div>
                ) : (
                    <PersonalStart />
                )}
            </div>

            <style>{`
                @keyframes dash-rise {
                    from { opacity: 0; transform: translateY(8px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .dash-rise { opacity: 0; animation: dash-rise 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards; }
                @media (prefers-reduced-motion: reduce) {
                    .dash-rise { opacity: 1; animation: none; }
                }
            `}</style>
        </>
    );
}

/** Quick actions for an employee without management access. */
function PersonalStart() {
    const { can } = usePermissions();

    const actions = [
        can('leave.request') && {
            href: '/leave',
            icon: <CalendarPlus className="size-5" />,
            title: 'File a leave request',
            desc: 'Request time off and track approvals.',
        },
        can('attendance.clock') && {
            href: '/attendance',
            icon: <Clock3 className="size-5" />,
            title: 'Attendance',
            desc: 'Review your daily time record.',
        },
        {
            href: '/settings/profile',
            icon: <UserRound className="size-5" />,
            title: 'Your profile',
            desc: 'Keep your details up to date.',
        },
    ].filter(Boolean) as {
        href: string;
        icon: ReactNode;
        title: string;
        desc: string;
    }[];

    return (
        <SectionCard
            title="Your workspace"
            icon={<UserRound className="size-4" />}
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {actions.map((action) => (
                    <Link
                        key={action.href}
                        href={action.href}
                        className="group flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:border-[#0ABFBF]/50 hover:bg-muted/40 dark:border-sidebar-border"
                    >
                        <span className="flex size-10 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                            {action.icon}
                        </span>
                        <span className="flex items-center gap-1 text-sm font-medium">
                            {action.title}
                            <ArrowUpRight className="size-3.5 text-muted-foreground/60 transition-colors group-hover:text-foreground" />
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {action.desc}
                        </span>
                    </Link>
                ))}
            </div>
        </SectionCard>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
};

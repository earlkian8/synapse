import { usePage } from '@inertiajs/react';
import { greeting, longDate } from '../lib';
import type { DashboardProps } from '../types';

/**
 * The dashboard's signature: a deep-navy "command centre" band carrying the
 * greeting and a live pulse of the day. The faint node field echoes the workspace
 * picker, tying the brand together; everything else on the page stays light and
 * quiet so this is the one bold surface.
 */
export function DashboardHero({
    workforce,
    attendance,
    leave,
    recruitment,
    today,
}: Pick<
    DashboardProps,
    'workforce' | 'attendance' | 'leave' | 'recruitment' | 'today'
>) {
    const { auth } = usePage().props;
    const now = new Date(today);

    const firstName =
        auth.user.first_name || auth.user.full_name?.split(' ')[0] || 'there';
    const orgName = auth.organization?.name ?? 'your team';

    const pulse: { value: string; label: string; sub?: string }[] = [];

    if (workforce) {
        pulse.push({
            value: workforce.active.toLocaleString(),
            label: 'Active staff',
            sub:
                workforce.new_this_month > 0
                    ? `+${workforce.new_this_month} this month`
                    : undefined,
        });
    }

    if (attendance) {
        pulse.push({
            value: attendance.present.toLocaleString(),
            label: 'In today',
            sub: `of ${attendance.workforce}`,
        });
    }

    if (leave) {
        pulse.push({
            value: leave.pending.toLocaleString(),
            label: 'Leave to review',
        });
    }

    if (recruitment) {
        pulse.push({
            value: recruitment.open_postings.toLocaleString(),
            label: 'Open roles',
        });
    }

    return (
        <div className="relative overflow-hidden rounded-2xl bg-[#0F2044] px-6 py-6 text-white sm:px-8 sm:py-7">
            <NodeField />

            <div className="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p className="text-[11px] font-medium tracking-[0.18em] text-[#0ABFBF] uppercase">
                        {longDate(now)}
                    </p>
                    <h1 className="mt-1.5 text-2xl font-semibold tracking-tight sm:text-3xl">
                        {greeting(now)}, {firstName}
                    </h1>
                    <p className="mt-1 text-sm text-white/55">
                        Here's the pulse at {orgName} today.
                    </p>
                </div>

                {pulse.length > 0 && (
                    <dl className="flex flex-wrap gap-x-8 gap-y-3 sm:gap-x-10">
                        {pulse.map((stat) => (
                            <div key={stat.label} className="min-w-[64px]">
                                <dd className="text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl">
                                    {stat.value}
                                </dd>
                                <dt className="mt-0.5 text-xs text-white/55">
                                    {stat.label}
                                </dt>
                                {stat.sub && (
                                    <dt className="text-[11px] font-medium text-[#0ABFBF]">
                                        {stat.sub}
                                    </dt>
                                )}
                            </div>
                        ))}
                    </dl>
                )}
            </div>
        </div>
    );
}

/** A faint constellation backdrop — the brand's "synapse" motif, kept low-contrast. */
function NodeField() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 overflow-hidden"
        >
            <div className="absolute -top-24 -right-16 size-72 rounded-full bg-[#0ABFBF]/10 blur-3xl" />
            <svg
                className="absolute inset-0 size-full opacity-50"
                viewBox="0 0 800 240"
                preserveAspectRatio="xMidYMid slice"
                fill="none"
            >
                <g stroke="#0ABFBF" strokeWidth="0.6" strokeOpacity="0.22">
                    <line x1="60" y1="60" x2="200" y2="120" />
                    <line x1="200" y1="120" x2="360" y2="50" />
                    <line x1="360" y1="50" x2="520" y2="140" />
                    <line x1="520" y1="140" x2="680" y2="70" />
                    <line x1="200" y1="120" x2="300" y2="210" />
                    <line x1="520" y1="140" x2="620" y2="210" />
                </g>
                <g fill="#0ABFBF" fillOpacity="0.5">
                    {[
                        [60, 60],
                        [200, 120],
                        [360, 50],
                        [520, 140],
                        [680, 70],
                        [300, 210],
                        [620, 210],
                    ].map(([cx, cy]) => (
                        <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r={2.5} />
                    ))}
                </g>
            </svg>
        </div>
    );
}

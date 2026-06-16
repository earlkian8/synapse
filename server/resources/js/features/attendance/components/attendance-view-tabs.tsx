import { CalendarRange, ClipboardList, LineChart } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { AttendanceTab } from '../types';

const TABS: {
    value: AttendanceTab;
    label: string;
    hint: string;
    icon: LucideIcon;
}[] = [
    {
        value: 'today',
        label: "Today's Log",
        hint: 'Per-person day',
        icon: ClipboardList,
    },
    {
        value: 'weekly',
        label: 'Weekly View',
        hint: 'Mon–Sun matrix',
        icon: CalendarRange,
    },
    {
        value: 'monthly',
        label: 'Monthly Report',
        hint: 'Per-person totals',
        icon: LineChart,
    },
];

/**
 * The workspace's primary tabs — a daily log, a weekly grid and a monthly
 * report over the same roster. Rendered as a prominent segmented control.
 */
export function AttendanceViewTabs({
    value,
    onChange,
}: {
    value: AttendanceTab;
    onChange: (value: AttendanceTab) => void;
}) {
    return (
        <div
            role="tablist"
            aria-label="Attendance view"
            className="inline-flex w-full gap-1 rounded-xl border border-sidebar-border/70 bg-card p-1 shadow-sm sm:w-auto dark:border-sidebar-border"
        >
            {TABS.map(({ value: tab, label, hint, icon: Icon }) => {
                const active = value === tab;

                return (
                    <button
                        key={tab}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(tab)}
                        className={cn(
                            'group relative flex flex-1 items-center gap-2.5 rounded-lg px-3.5 py-2 text-left transition-all sm:flex-none',
                            active
                                ? 'bg-[#0ABFBF] text-white shadow-sm'
                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                        )}
                    >
                        <Icon
                            className={cn(
                                'size-4 shrink-0',
                                active
                                    ? 'text-white'
                                    : 'text-muted-foreground/70',
                            )}
                        />
                        <span className="flex flex-col leading-tight">
                            <span className="text-sm font-semibold">
                                {label}
                            </span>
                            <span
                                className={cn(
                                    'hidden text-[11px] sm:block',
                                    active
                                        ? 'text-white/80'
                                        : 'text-muted-foreground/70',
                                )}
                            >
                                {hint}
                            </span>
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

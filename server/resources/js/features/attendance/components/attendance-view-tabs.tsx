import { cn } from '@/lib/utils';
import type { AttendanceTab } from '../types';

const TABS: { value: AttendanceTab; label: string }[] = [
    { value: 'today', label: "Today's Log" },
    { value: 'weekly', label: 'Weekly View' },
    { value: 'monthly', label: 'Monthly Report' },
    { value: 'roster', label: 'Roster' },
    { value: 'requests', label: 'Requests' },
    { value: 'periods', label: 'Periods' },
];

/**
 * The workspace's primary tabs — what happened (a daily log, a weekly grid, a
 * monthly report), what is meant to (the roster), what people asked for and
 * what attendance closes on (ADR 0039). Uses the shared underline tab style.
 * Each tab past the first three is only offered to someone who may use it, and
 * Requests carries how many are waiting.
 */
export function AttendanceViewTabs({
    value,
    visible,
    pendingRequests,
    onChange,
}: {
    value: AttendanceTab;
    visible: Partial<Record<AttendanceTab, boolean>>;
    pendingRequests: number;
    onChange: (value: AttendanceTab) => void;
}) {
    const tabs = TABS.filter((tab) => visible[tab.value] ?? true);

    return (
        <div
            className="-mb-px flex items-center gap-1 overflow-x-auto border-b border-border"
            role="tablist"
            aria-label="Attendance view"
        >
            {tabs.map((tab) => {
                const active = value === tab.value;

                return (
                    <button
                        key={tab.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(tab.value)}
                        className={cn(
                            'relative border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                            active
                                ? 'border-[#0ABFBF] text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.label}
                        {tab.value === 'requests' && pendingRequests > 0 && (
                            <span className="ml-1.5 rounded-full bg-amber-500/15 px-1.5 py-px text-[11px] font-semibold text-amber-700 tabular-nums dark:text-amber-300">
                                {pendingRequests}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

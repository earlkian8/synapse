import { cn } from '@/lib/utils';
import type { AttendanceTab } from '../types';

const TABS: { value: AttendanceTab; label: string }[] = [
    { value: 'today', label: "Today's Log" },
    { value: 'weekly', label: 'Weekly View' },
    { value: 'monthly', label: 'Monthly Report' },
    { value: 'roster', label: 'Roster' },
];

/**
 * The workspace's primary tabs — what happened (a daily log, a weekly grid, a
 * monthly report) and what is meant to (the roster). Uses the shared underline
 * tab style. The roster is only offered to someone who may see it.
 */
export function AttendanceViewTabs({
    value,
    canViewRoster,
    onChange,
}: {
    value: AttendanceTab;
    canViewRoster: boolean;
    onChange: (value: AttendanceTab) => void;
}) {
    const tabs = TABS.filter((tab) => tab.value !== 'roster' || canViewRoster);

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
                    </button>
                );
            })}
        </div>
    );
}

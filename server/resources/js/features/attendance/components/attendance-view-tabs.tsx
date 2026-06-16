import { cn } from '@/lib/utils';
import type { AttendanceTab } from '../types';

const TABS: { value: AttendanceTab; label: string }[] = [
    { value: 'today', label: "Today's Log" },
    { value: 'weekly', label: 'Weekly View' },
    { value: 'monthly', label: 'Monthly Report' },
];

/**
 * The workspace's primary tabs — a daily log, a weekly grid and a monthly
 * report over the same roster. Uses the shared underline tab style.
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
            className="-mb-px flex items-center gap-1 overflow-x-auto border-b border-border"
            role="tablist"
            aria-label="Attendance view"
        >
            {TABS.map((tab) => {
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

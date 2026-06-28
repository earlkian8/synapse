import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { groupIcon } from '../lib';
import type { ReportListItem } from '../types';

/**
 * The workspace's report picker. A grouped vertical rail on desktop; a single select
 * on small screens. Selecting a report swaps the detail pane in place — no navigation.
 */
export function ReportRail({
    reports,
    activeKey,
    onSelect,
}: {
    reports: ReportListItem[];
    activeKey: string;
    onSelect: (key: string) => void;
}) {
    const groups = groupReports(reports);

    return (
        <>
            {/* Small screens: a compact select. */}
            <div className="lg:hidden">
                <Select value={activeKey} onValueChange={onSelect}>
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Choose a report" />
                    </SelectTrigger>
                    <SelectContent>
                        {reports.map((report) => (
                            <SelectItem key={report.key} value={report.key}>
                                {report.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {/* Desktop: the grouped rail. */}
            <nav className="hidden lg:flex lg:flex-col lg:gap-5">
                {groups.map(([group, items]) => {
                    const Icon = groupIcon(group);

                    return (
                        <div key={group} className="flex flex-col gap-1">
                            <p className="flex items-center gap-1.5 px-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                <Icon className="size-3.5" />
                                {group}
                            </p>
                            {items.map((report) => (
                                <button
                                    key={report.key}
                                    type="button"
                                    onClick={() => onSelect(report.key)}
                                    className={cn(
                                        'flex w-full items-center rounded-md border-l-2 px-2.5 py-1.5 text-left text-sm transition-colors',
                                        report.key === activeKey
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 font-medium text-foreground'
                                            : 'border-transparent text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                    )}
                                >
                                    {report.name}
                                </button>
                            ))}
                        </div>
                    );
                })}
            </nav>
        </>
    );
}

/** Preserve the server's report order while collecting them into sections. */
function groupReports(reports: ReportListItem[]): [string, ReportListItem[]][] {
    const map = new Map<string, ReportListItem[]>();

    for (const report of reports) {
        const bucket = map.get(report.group) ?? [];
        bucket.push(report);
        map.set(report.group, bucket);
    }

    return Array.from(map.entries());
}

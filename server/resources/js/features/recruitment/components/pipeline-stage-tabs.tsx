import { cn } from '@/lib/utils';
import { PIPELINE_STAGES } from '../constants';
import type { Stage, StageFilter } from '../types';

type Props = {
    value: StageFilter;
    counts: Record<Stage, number>;
    total: number;
    onChange: (value: StageFilter) => void;
};

/**
 * Stage tabs for the pipeline table — All plus one per stage (Applied …
 * Rejected), each with a live count, filtering the candidates below.
 */
export function PipelineStageTabs({ value, counts, total, onChange }: Props) {
    const tabs: { value: StageFilter; label: string; count: number }[] = [
        { value: 'all', label: 'All', count: total },
        ...PIPELINE_STAGES.map((stage) => ({
            value: stage.value,
            label: stage.label,
            count: counts[stage.value],
        })),
    ];

    return (
        <div
            className="-mb-px flex items-center gap-1 overflow-x-auto border-b border-border"
            role="tablist"
            aria-label="Filter candidates by stage"
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
                            'relative inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                            active
                                ? 'border-[#0ABFBF] text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.label}
                        <span
                            className={cn(
                                'rounded-full px-1.5 py-0.5 text-xs tabular-nums',
                                active
                                    ? 'bg-[#0ABFBF]/10 text-[#0ABFBF]'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {tab.count}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

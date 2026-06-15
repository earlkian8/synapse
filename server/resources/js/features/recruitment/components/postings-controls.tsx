import { LayoutGrid, List } from 'lucide-react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { STATUS_FILTERS } from '../constants';
import type { PostingsView } from '../types';

type Props = {
    status: string;
    view: PostingsView;
    onStatus: (value: string) => void;
    onView: (value: PostingsView) => void;
};

/**
 * The status tabs (All / Draft / Open / …) paired with the table/grid view
 * switch — the primary way a recruiter slices and lays out the postings board.
 */
export function PostingsControls({ status, view, onStatus, onView }: Props) {
    return (
        <div className="flex flex-col gap-3 border-b border-border sm:flex-row sm:items-center sm:justify-between">
            <div
                className="-mb-px flex items-center gap-1 overflow-x-auto"
                role="tablist"
                aria-label="Filter postings by status"
            >
                {STATUS_FILTERS.map((tab) => {
                    const active = status === tab.value;

                    return (
                        <button
                            key={tab.value}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            onClick={() => onStatus(tab.value)}
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

            <ToggleGroup
                type="single"
                value={view}
                onValueChange={(value) =>
                    value && onView(value as PostingsView)
                }
                variant="outline"
                size="sm"
                className="mb-2 self-end sm:mb-0 sm:self-auto"
            >
                <ToggleGroupItem value="table" aria-label="Table view">
                    <List className="size-4" />
                </ToggleGroupItem>
                <ToggleGroupItem value="grid" aria-label="Card view">
                    <LayoutGrid className="size-4" />
                </ToggleGroupItem>
            </ToggleGroup>
        </div>
    );
}

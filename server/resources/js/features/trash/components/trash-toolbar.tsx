import { Layers, Search, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { TYPE_META } from '../constants';
import type { TrashFilters, TrashSummary } from '../types';

type Props = {
    filters: TrashFilters;
    summary: TrashSummary;
    onSearch: (value: string) => void;
    onType: (value: string) => void;
    onEmpty: () => void;
};

export function TrashToolbar({
    filters,
    summary,
    onSearch,
    onType,
    onEmpty,
}: Props) {
    const [term, setTerm] = useState(filters.search);
    const [syncedSearch, setSyncedSearch] = useState(filters.search);

    // Re-sync the field when the server filter changes — render-phase adjustment
    // is preferred over an effect for deriving state from props.
    if (filters.search !== syncedSearch) {
        setSyncedSearch(filters.search);
        setTerm(filters.search);
    }

    // Debounce the search input before hitting the server.
    useEffect(() => {
        const handle = window.setTimeout(() => {
            if (term !== filters.search) {
                onSearch(term);
            }
        }, 350);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    const canEmpty =
        summary.total > 0 &&
        summary.types.some((type) => type.can_force_delete);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="relative w-full sm:w-80">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Search archived items…"
                        className="pl-9"
                        aria-label="Search trash"
                    />
                    {term && (
                        <button
                            type="button"
                            onClick={() => setTerm('')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                            aria-label="Clear search"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>

                {canEmpty && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onEmpty}
                        className="self-start text-destructive hover:text-destructive lg:self-auto"
                    >
                        <Trash2 className="size-4" />
                        Empty trash
                    </Button>
                )}
            </div>

            {/* Type tabs */}
            <div className="flex flex-wrap items-center gap-1.5">
                <TypeTab
                    active={filters.type === 'all'}
                    icon={Layers}
                    label="All"
                    count={summary.total}
                    onClick={() => onType('all')}
                />
                {summary.types.map((type) => (
                    <TypeTab
                        key={type.key}
                        active={filters.type === type.key}
                        icon={TYPE_META[type.key].icon}
                        label={type.plural}
                        count={type.count}
                        onClick={() => onType(type.key)}
                    />
                ))}
            </div>
        </div>
    );
}

function TypeTab({
    active,
    icon: Icon,
    label,
    count,
    onClick,
}: {
    active: boolean;
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    count: number;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-sm font-medium transition-colors',
                active
                    ? 'border-[#0ABFBF]/50 bg-[#0ABFBF]/10 text-[#0ABFBF]'
                    : 'border-border bg-muted/20 text-muted-foreground hover:bg-muted/40 hover:text-foreground',
            )}
        >
            <Icon className="size-4" />
            {label}
            <span
                className={cn(
                    'rounded-full px-1.5 py-0.5 text-[11px] tabular-nums',
                    active
                        ? 'bg-[#0ABFBF]/15 text-[#0ABFBF]'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                {count}
            </span>
        </button>
    );
}

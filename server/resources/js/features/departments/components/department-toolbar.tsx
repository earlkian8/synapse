import { Archive, Plus, Search, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Props = {
    search: string;
    onSearch: (value: string) => void;
    showArchived: boolean;
    onToggleArchived: () => void;
    archivedCount: number;
    canManage: boolean;
    onCreate: () => void;
};

export function DepartmentToolbar({
    search,
    onSearch,
    showArchived,
    onToggleArchived,
    archivedCount,
    canManage,
    onCreate,
}: Props) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-1 flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-72">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => onSearch(event.target.value)}
                        placeholder="Search departments…"
                        className="pl-9"
                        aria-label="Search departments"
                    />
                    {search && (
                        <button
                            type="button"
                            onClick={() => onSearch('')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                            aria-label="Clear search"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>

                {archivedCount > 0 && (
                    <Button
                        variant={showArchived ? 'secondary' : 'ghost'}
                        size="sm"
                        onClick={onToggleArchived}
                        className={cn(!showArchived && 'text-muted-foreground')}
                    >
                        <Archive className="size-4" />
                        Archived
                        <span className="ml-0.5 tabular-nums">
                            ({archivedCount})
                        </span>
                    </Button>
                )}
            </div>

            {canManage && (
                <Button size="sm" onClick={onCreate}>
                    <Plus className="size-4" />
                    New department
                </Button>
            )}
        </div>
    );
}

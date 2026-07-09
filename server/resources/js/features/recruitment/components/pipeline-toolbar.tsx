import { ArrowUpDown, Download, Search, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PipelineSort } from '../types';

const SORT_OPTIONS: { value: PipelineSort; label: string }[] = [
    { value: 'default', label: 'Best match' },
    { value: 'fit', label: 'Highest fit' },
    { value: 'rating', label: 'Highest rating' },
    { value: 'recent', label: 'Newest first' },
    { value: 'oldest', label: 'Oldest first' },
    { value: 'name', label: 'Name (A–Z)' },
];

type Props = {
    search: string;
    sort: PipelineSort;
    shown: number;
    total: number;
    canExport: boolean;
    exportUrl: string;
    onSearch: (value: string) => void;
    onSort: (value: PipelineSort) => void;
};

/**
 * The pipeline board's utility bar — client-side candidate search, sort, a live
 * result count, and a CSV export of the whole pipeline.
 */
export function PipelineToolbar({
    search,
    sort,
    shown,
    total,
    canExport,
    exportUrl,
    onSearch,
    onSort,
}: Props) {
    const filtering = search.trim() !== '';

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-1 flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => onSearch(event.target.value)}
                        placeholder="Search candidates…"
                        className="pl-9"
                        aria-label="Search candidates"
                    />
                    {filtering && (
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

                <Select
                    value={sort}
                    onValueChange={(value) => onSort(value as PipelineSort)}
                >
                    <SelectTrigger
                        className="w-[150px]"
                        aria-label="Sort candidates"
                    >
                        <ArrowUpDown className="size-4 text-muted-foreground" />
                        <SelectValue placeholder="Sort" />
                    </SelectTrigger>
                    <SelectContent>
                        {SORT_OPTIONS.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <span className="text-xs text-muted-foreground tabular-nums">
                    {filtering ? `${shown} of ${total}` : `${total}`} candidate
                    {total === 1 && !filtering ? '' : 's'}
                </span>
            </div>

            {canExport && (
                <Button variant="outline" size="sm" asChild>
                    <a href={exportUrl}>
                        <Download className="size-4" />
                        Export
                    </a>
                </Button>
            )}
        </div>
    );
}

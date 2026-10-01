import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { PER_PAGE_OPTIONS } from '../constants';
import type { PaginationMeta } from '../types';

type Props = {
    meta: PaginationMeta;
    perPage: number;
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
};

/**
 * Build a compact page-number window with ellipses, e.g. 1 … 4 5 [6] 7 8 … 20.
 */
function pageWindow(current: number, last: number): (number | '…')[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    const pages: (number | '…')[] = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);

    if (start > 2) {
        pages.push('…');
    }

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    if (end < last - 1) {
        pages.push('…');
    }

    pages.push(last);

    return pages;
}

export function RolesPagination({ meta, perPage, onPage, onPerPage }: Props) {
    const { current_page, last_page, from, to, total } = meta;

    return (
        <div className="flex flex-col gap-3 px-1 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-3 text-sm text-muted-foreground">
                <span>
                    {total > 0 ? (
                        <>
                            Showing{' '}
                            <span className="font-medium text-foreground">
                                {from}
                            </span>
                            –
                            <span className="font-medium text-foreground">
                                {to}
                            </span>{' '}
                            of{' '}
                            <span className="font-medium text-foreground">
                                {total.toLocaleString()}
                            </span>
                        </>
                    ) : (
                        'No results'
                    )}
                </span>

                <span className="hidden items-center gap-2 sm:flex">
                    <span className="h-4 w-px bg-border" />
                    <span>Rows</span>
                    <Select
                        value={String(perPage)}
                        onValueChange={(value) => onPerPage(Number(value))}
                    >
                        <SelectTrigger size="sm" className="h-8 w-[72px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PER_PAGE_OPTIONS.map((option) => (
                                <SelectItem key={option} value={String(option)}>
                                    {option}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </span>
            </div>

            <div className="flex items-center gap-1">
                <Button
                    variant="outline"
                    size="icon"
                    className="size-8"
                    disabled={current_page <= 1}
                    onClick={() => onPage(current_page - 1)}
                    aria-label="Previous page"
                >
                    <ChevronLeft className="size-4" />
                </Button>

                {pageWindow(current_page, last_page).map((page, index) =>
                    page === '…' ? (
                        <span
                            key={`ellipsis-${index}`}
                            className="px-1.5 text-sm text-muted-foreground"
                        >
                            …
                        </span>
                    ) : (
                        <Button
                            key={page}
                            variant={
                                page === current_page ? 'default' : 'outline'
                            }
                            size="icon"
                            className={cn(
                                'size-8 text-sm tabular-nums',
                                page === current_page &&
                                    'bg-[#0F2044] hover:bg-[#0F2044]/90',
                            )}
                            onClick={() => onPage(page)}
                        >
                            {page}
                        </Button>
                    ),
                )}

                <Button
                    variant="outline"
                    size="icon"
                    className="size-8"
                    disabled={current_page >= last_page}
                    onClick={() => onPage(current_page + 1)}
                    aria-label="Next page"
                >
                    <ChevronRight className="size-4" />
                </Button>
            </div>
        </div>
    );
}

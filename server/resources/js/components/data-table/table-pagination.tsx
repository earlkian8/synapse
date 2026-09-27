import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

export const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100] as const;

export type PageMeta = {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

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

/**
 * "Showing 1–15 of 120 · Rows [15]" and the page buttons, under every table —
 * whether the server pages it or the page does ({@link useClientPagination}).
 */
export function TablePagination({
    meta,
    perPage,
    onPage,
    onPerPage,
}: {
    meta: PageMeta;
    perPage: number;
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}) {
    const { current_page, last_page, from, to, total } = meta;

    return (
        <div className="flex flex-col gap-2 px-1 sm:flex-row sm:items-center sm:justify-between">
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
                        <SelectTrigger
                            size="sm"
                            className="h-8 w-[72px]"
                            aria-label="Rows per page"
                        >
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

            {last_page > 1 && (
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
                                    page === current_page
                                        ? 'default'
                                        : 'outline'
                                }
                                size="icon"
                                aria-current={
                                    page === current_page ? 'page' : undefined
                                }
                                className={cn(
                                    'size-8 text-sm tabular-nums',
                                    page === current_page &&
                                        'bg-[#0F2044] hover:bg-[#0F2044]/90 dark:bg-[#0ABFBF] dark:text-[#0F2044] dark:hover:bg-[#0ABFBF]/90',
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
            )}
        </div>
    );
}

/**
 * Pages a list the page already holds (filtered and sorted in the browser), so it
 * reads exactly like a server-paged table. Back to page 1 whenever `resetKey` —
 * the filters, the sort — changes.
 */
export function useClientPagination<T>(
    items: T[],
    resetKey: string,
    initialPerPage = 15,
) {
    const [page, setPage] = useState(1);
    const [perPage, setPerPageState] = useState(initialPerPage);
    const [key, setKey] = useState(resetKey);

    if (key !== resetKey) {
        setKey(resetKey);
        setPage(1);
    }

    const total = items.length;
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const current = Math.min(page, lastPage);
    const start = (current - 1) * perPage;
    const rows = items.slice(start, start + perPage);

    return {
        rows,
        perPage,
        meta: {
            current_page: current,
            last_page: lastPage,
            from: total === 0 ? null : start + 1,
            to: total === 0 ? null : start + rows.length,
            total,
        } satisfies PageMeta,
        setPage,
        setPerPage: (value: number) => {
            setPerPageState(value);
            setPage(1);
        },
    };
}

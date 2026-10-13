import { RotateCcw, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { TableCell, TableRow } from '@/components/ui/table';

/** While the session's admin is being read. */
export function FullPageSpinner() {
    return (
        <div className="flex min-h-svh items-center justify-center bg-background">
            <Spinner className="size-6 text-muted-foreground" />
        </div>
    );
}

/** A request failed: what happened, and a way to try again. */
export function LoadError({
    message,
    onRetry,
}: {
    message: string;
    onRetry: () => void;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border px-6 py-12 text-center">
            <span className="flex size-10 items-center justify-center rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400">
                <TriangleAlert className="size-5" />
            </span>
            <p className="text-sm font-medium">This didn't load</p>
            <p className="max-w-sm text-sm text-muted-foreground">{message}</p>
            <Button
                variant="outline"
                size="sm"
                className="mt-1"
                onClick={onRetry}
            >
                <RotateCcw className="size-4" />
                Try again
            </Button>
        </div>
    );
}

/** Placeholder rows at the table's density while the first page loads. */
export function SkeletonRows({
    columns,
    rows = 6,
}: {
    columns: number;
    rows?: number;
}) {
    return Array.from({ length: rows }, (_, row) => (
        <TableRow key={row} className="hover:bg-transparent">
            {Array.from({ length: columns }, (_, column) => (
                <TableCell key={column}>
                    <Skeleton className="h-4 w-full max-w-36" />
                </TableCell>
            ))}
        </TableRow>
    ));
}

const TILE_COLUMNS: Record<number, string> = {
    2: 'lg:grid-cols-2',
    3: 'lg:grid-cols-3',
    4: 'lg:grid-cols-4',
    5: 'lg:grid-cols-5',
};

/** Placeholder tiles while counts load, laid out as StatTiles will be. */
export function SkeletonTiles({ count }: { count: number }) {
    return (
        <div
            className={`grid grid-cols-2 gap-2.5 sm:grid-cols-3 ${TILE_COLUMNS[count] ?? 'lg:grid-cols-4'}`}
        >
            {Array.from({ length: count }, (_, index) => (
                <Skeleton key={index} className="h-[58px] rounded-xl" />
            ))}
        </div>
    );
}

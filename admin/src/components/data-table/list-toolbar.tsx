import { RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/**
 * The row above a table: search and filters on the left (with Reset once any is
 * set), the list's own actions on the right.
 */
export function ListToolbar({
    children,
    actions,
    filtered = false,
    onReset,
    summary,
}: {
    children: ReactNode;
    actions?: ReactNode;
    /** Whether any filter is set, which shows Reset. */
    filtered?: boolean;
    onReset?: () => void;
    /** Right-aligned text before the actions, e.g. "12 of 40". */
    summary?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-1 flex-wrap items-center gap-2">
                {children}
                {filtered && onReset && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onReset}
                        className="text-muted-foreground"
                    >
                        <RotateCcw className="size-4" />
                        Reset
                    </Button>
                )}
            </div>
            {(summary || actions) && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {summary && (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {summary}
                        </span>
                    )}
                    {actions}
                </div>
            )}
        </div>
    );
}

export type FilterOption = { value: string; label: string };

/** A filter dropdown at the toolbar's height. */
export function FilterSelect({
    value,
    onChange,
    options,
    label,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    options: readonly FilterOption[];
    label: string;
    className?: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger
                className={cn('h-9 w-40', className)}
                aria-label={label}
            >
                <SelectValue placeholder={label} />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

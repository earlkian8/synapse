import { RotateCcw, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ReportFilter } from '../types';

/**
 * The report toolbar: renders a report's declared filters and reports every change up
 * as a query patch (the runner re-fetches). Selects, dates and month apply on change;
 * the search box debounces so typing doesn't fire a request per keystroke.
 */
export function ReportFilters({
    filters,
    applied,
    onChange,
    onReset,
}: {
    filters: ReportFilter[];
    applied: Record<string, string>;
    onChange: (patch: Record<string, string>) => void;
    onReset: () => void;
}) {
    if (filters.length === 0) {
        return null;
    }

    return (
        <div className="report-no-print flex flex-wrap items-end gap-3 rounded-lg border border-sidebar-border/70 bg-muted/30 p-3 dark:border-sidebar-border">
            {filters.map((filter) => (
                <FilterControl
                    key={filter.key}
                    filter={filter}
                    applied={applied}
                    onChange={onChange}
                />
            ))}

            <Button
                variant="ghost"
                size="sm"
                className="ml-auto h-9 text-muted-foreground"
                onClick={onReset}
            >
                <RotateCcw className="size-3.5" />
                Reset
            </Button>
        </div>
    );
}

function FilterControl({
    filter,
    applied,
    onChange,
}: {
    filter: ReportFilter;
    applied: Record<string, string>;
    onChange: (patch: Record<string, string>) => void;
}) {
    if (filter.type === 'select') {
        return (
            <Field label={filter.label}>
                <Select
                    value={applied[filter.key] ?? 'all'}
                    onValueChange={(value) => onChange({ [filter.key]: value })}
                >
                    <SelectTrigger size="sm" className="h-9 w-48">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(filter.options ?? []).map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
        );
    }

    if (filter.type === 'month') {
        return (
            <Field label={filter.label}>
                <Input
                    type="month"
                    value={applied.month ?? ''}
                    onChange={(event) =>
                        onChange({ month: event.target.value })
                    }
                    className="h-9 w-40"
                />
            </Field>
        );
    }

    if (filter.type === 'daterange') {
        return (
            <Field label={filter.label}>
                <div className="flex items-center gap-1.5">
                    <Input
                        type="date"
                        value={applied.start ?? ''}
                        max={applied.end}
                        onChange={(event) =>
                            onChange({ start: event.target.value })
                        }
                        className="h-9 w-[9.5rem]"
                        aria-label={`${filter.label} from`}
                    />
                    <span className="text-xs text-muted-foreground">to</span>
                    <Input
                        type="date"
                        value={applied.end ?? ''}
                        min={applied.start}
                        onChange={(event) =>
                            onChange({ end: event.target.value })
                        }
                        className="h-9 w-[9.5rem]"
                        aria-label={`${filter.label} to`}
                    />
                </div>
            </Field>
        );
    }

    return (
        <SearchField filter={filter} applied={applied} onChange={onChange} />
    );
}

/** Debounced search box — only fires a request once typing settles. */
function SearchField({
    filter,
    applied,
    onChange,
}: {
    filter: ReportFilter;
    applied: Record<string, string>;
    onChange: (patch: Record<string, string>) => void;
}) {
    const current = applied[filter.key] ?? '';
    const [value, setValue] = useState(current);
    const [synced, setSynced] = useState(current);

    // Adopt the applied value when it changes from outside (e.g. Reset) — the
    // codebase's "adjust state during render" pattern, no effect needed.
    if (current !== synced) {
        setSynced(current);
        setValue(current);
    }

    // Debounce the query so typing doesn't fire a request per keystroke. Only the
    // navigation is external here — no state is set in the effect.
    useEffect(() => {
        if (value === current) {
            return;
        }

        const id = setTimeout(() => onChange({ [filter.key]: value }), 350);

        return () => clearTimeout(id);
    }, [value, current, filter.key, onChange]);

    return (
        <Field label={filter.label}>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    placeholder={filter.placeholder ?? 'Search…'}
                    className="h-9 w-56 pl-8"
                />
            </div>
        </Field>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1">
            <Label className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </Label>
            {children}
        </div>
    );
}

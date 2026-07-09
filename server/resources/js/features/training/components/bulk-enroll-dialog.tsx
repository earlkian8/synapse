import { Search, TriangleAlert, UserPlus, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { enrollEmployees } from '../api';
import type { EnrollableEmployee } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    programHashid: string;
    enrollable: EnrollableEmployee[];
    seatsRemaining: number | null;
};

/**
 * Enroll many employees into a program at once: search, filter by department,
 * select-all-visible, and enroll the selection in a single request. Warns when
 * the selection exceeds the seats that remain (the server enrolls what fits).
 */
export function BulkEnrollDialog(props: Props) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            <DialogContent className="flex max-h-[85vh] flex-col sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Enroll employees</DialogTitle>
                    <DialogDescription>
                        Add one or more employees to this training program.
                    </DialogDescription>
                </DialogHeader>

                {props.open && <Body key="body" {...props} />}
            </DialogContent>
        </Dialog>
    );
}

function Body({
    onOpenChange,
    programHashid,
    enrollable,
    seatsRemaining,
}: Props) {
    const [search, setSearch] = useState('');
    const [dept, setDept] = useState('all');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [processing, setProcessing] = useState(false);

    const departments = useMemo(() => {
        const set = new Set<string>();

        for (const employee of enrollable) {
            if (employee.department) {
                set.add(employee.department);
            }
        }

        return [...set].sort((a, b) => a.localeCompare(b));
    }, [enrollable]);

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return enrollable.filter((employee) => {
            if (dept !== 'all' && employee.department !== dept) {
                return false;
            }

            if (
                needle !== '' &&
                !employee.full_name.toLowerCase().includes(needle) &&
                !employee.employee_no.toLowerCase().includes(needle)
            ) {
                return false;
            }

            return true;
        });
    }, [enrollable, search, dept]);

    const allVisibleSelected =
        filtered.length > 0 && filtered.every((e) => selected.has(e.id));

    const toggle = (id: number) => {
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const toggleAllVisible = () => {
        setSelected((prev) => {
            const next = new Set(prev);

            if (allVisibleSelected) {
                for (const e of filtered) {
                    next.delete(e.id);
                }
            } else {
                for (const e of filtered) {
                    next.add(e.id);
                }
            }

            return next;
        });
    };

    const overCapacity =
        seatsRemaining !== null && selected.size > seatsRemaining;

    const submit = () => {
        if (selected.size === 0) {
            return;
        }

        enrollEmployees(programHashid, [...selected], {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    if (enrollable.length === 0) {
        return (
            <p className="rounded-md border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                Every active employee is already enrolled in this program.
            </p>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <div className="flex flex-col gap-2 sm:flex-row">
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search employees…"
                        className="pl-9"
                    />
                    {search !== '' && (
                        <button
                            type="button"
                            onClick={() => setSearch('')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                            aria-label="Clear search"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
                {departments.length > 0 && (
                    <Select value={dept} onValueChange={setDept}>
                        <SelectTrigger className="sm:w-48">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All departments</SelectItem>
                            {departments.map((d) => (
                                <SelectItem key={d} value={d}>
                                    {d}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </div>

            {/* Select-all + count */}
            <div className="flex items-center justify-between px-1">
                <label className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                    <Checkbox
                        checked={allVisibleSelected}
                        onCheckedChange={toggleAllVisible}
                        disabled={filtered.length === 0}
                    />
                    Select all
                    {filtered.length !== enrollable.length &&
                        ` (${filtered.length})`}
                </label>
                <span className="text-xs text-muted-foreground tabular-nums">
                    {selected.size} selected
                </span>
            </div>

            {/* Employee list */}
            <div className="min-h-0 flex-1 overflow-y-auto rounded-lg border border-border">
                {filtered.length === 0 ? (
                    <p className="px-3 py-8 text-center text-sm text-muted-foreground">
                        No employees match these filters.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {filtered.map((employee) => (
                            <li key={employee.id}>
                                <label className="flex cursor-pointer items-center gap-3 px-3 py-2 transition-colors hover:bg-muted/50">
                                    <Checkbox
                                        checked={selected.has(employee.id)}
                                        onCheckedChange={() =>
                                            toggle(employee.id)
                                        }
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {employee.full_name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {employee.employee_no}
                                            {employee.department
                                                ? ` · ${employee.department}`
                                                : ''}
                                        </p>
                                    </div>
                                </label>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {overCapacity && (
                <p className="flex items-start gap-2 rounded-md bg-amber-500/10 px-3 py-2 text-xs text-amber-600 dark:text-amber-400">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                    Only {seatsRemaining} seat
                    {seatsRemaining === 1 ? '' : 's'} left — the extra selections
                    won't be enrolled.
                </p>
            )}

            <DialogFooter className="mt-1">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onOpenChange(false)}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={submit}
                    disabled={processing || selected.size === 0}
                    className={cn(processing && 'opacity-80')}
                >
                    {processing && <Spinner />}
                    <UserPlus className="size-4" />
                    Enroll
                    {selected.size > 0 ? ` ${selected.size}` : ''}
                </Button>
            </DialogFooter>
        </div>
    );
}

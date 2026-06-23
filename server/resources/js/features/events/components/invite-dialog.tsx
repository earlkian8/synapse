import { Search } from 'lucide-react';
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
import { Spinner } from '@/components/ui/spinner';
import { inviteAttendees } from '../api';
import type { InvitableEmployee } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    eventHashid: string;
    invitable: InvitableEmployee[];
};

/** Invite one or more employees to an event with a searchable, multi-select list. */
export function InviteDialog({
    open,
    onOpenChange,
    eventHashid,
    invitable,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Invite attendees</DialogTitle>
                    <DialogDescription>
                        Pick the employees to invite. They'll be notified if
                        they have a linked account.
                    </DialogDescription>
                </DialogHeader>

                {open && (
                    <Body
                        eventHashid={eventHashid}
                        invitable={invitable}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function Body({
    eventHashid,
    invitable,
    onDone,
}: {
    eventHashid: string;
    invitable: InvitableEmployee[];
    onDone: () => void;
}) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [processing, setProcessing] = useState(false);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return invitable;
        }

        return invitable.filter(
            (e) =>
                e.full_name.toLowerCase().includes(q) ||
                e.employee_no.toLowerCase().includes(q),
        );
    }, [invitable, query]);

    const toggle = (id: number) =>
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const allFilteredSelected =
        filtered.length > 0 && filtered.every((e) => selected.has(e.id));

    const toggleAll = () =>
        setSelected((prev) => {
            const next = new Set(prev);

            if (allFilteredSelected) {
                filtered.forEach((e) => next.delete(e.id));
            } else {
                filtered.forEach((e) => next.add(e.id));
            }

            return next;
        });

    const submit = () => {
        if (selected.size === 0) {
            return;
        }

        inviteAttendees(eventHashid, Array.from(selected), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onDone,
        });
    };

    if (invitable.length === 0) {
        return (
            <>
                <p className="rounded-md border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                    Every active employee is already invited.
                </p>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        Close
                    </Button>
                </DialogFooter>
            </>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="relative">
                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search employees"
                    className="pl-8"
                />
            </div>

            <div className="flex items-center justify-between px-1 text-xs text-muted-foreground">
                <button
                    type="button"
                    onClick={toggleAll}
                    className="font-medium text-[#0a8b91] hover:underline dark:text-[#0ABFBF]"
                >
                    {allFilteredSelected ? 'Clear all' : 'Select all'}
                </button>
                <span className="tabular-nums">{selected.size} selected</span>
            </div>

            <ul className="max-h-64 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                {filtered.length === 0 ? (
                    <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                        No matches.
                    </li>
                ) : (
                    filtered.map((e) => (
                        <li key={e.id}>
                            <label className="flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors hover:bg-muted/50">
                                <Checkbox
                                    checked={selected.has(e.id)}
                                    onCheckedChange={() => toggle(e.id)}
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {e.full_name}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {e.employee_no}
                                    </span>
                                </span>
                            </label>
                        </li>
                    ))
                )}
            </ul>

            <DialogFooter className="mt-1">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={submit}
                    disabled={processing || selected.size === 0}
                >
                    {processing && <Spinner />}
                    Invite
                    {selected.size > 0 ? ` (${selected.size})` : ''}
                </Button>
            </DialogFooter>
        </div>
    );
}

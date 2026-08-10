import { Search, UserRoundSearch } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { JoinRequest, UnlinkedEmployee } from '../types';
import { EmployeeAvatar } from './employee-avatar';

type Props = {
    request: JoinRequest | null;
    candidates: UnlinkedEmployee[];
    open: boolean;
    processing: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: (employeeId: number) => void;
};

/**
 * Picks which roster line an approved join request becomes.
 *
 * This is the one irreversible judgement on the App Access screen — bind the wrong
 * record and somebody is handed another person's 201 file — so the dialog puts the
 * requester's own name and address at the top and keeps them visible while the
 * choice is made, rather than making HR remember who they were approving.
 *
 * Candidates whose email matches the requester's are floated to the top and marked,
 * since that is nearly always the right answer and is worth showing rather than
 * hiding behind a search.
 */
export function LinkEmployeeDialog({
    request,
    candidates,
    open,
    processing,
    onOpenChange,
    onConfirm,
}: Props) {
    const [search, setSearch] = useState('');
    const [chosen, setChosen] = useState<number | null>(null);

    const requesterEmail = request?.user?.email?.toLowerCase() ?? null;

    const results = useMemo(() => {
        const needle = search.trim().toLowerCase();

        const matches = candidates.filter((candidate) => {
            if (needle === '') {
                return true;
            }

            return (
                candidate.full_name.toLowerCase().includes(needle) ||
                candidate.employee_no.toLowerCase().includes(needle) ||
                (candidate.email ?? '').toLowerCase().includes(needle)
            );
        });

        // Likely matches first, then alphabetical (the server already sorted).
        return [...matches].sort((a, b) => {
            const aLikely = (a.email ?? '').toLowerCase() === requesterEmail;
            const bLikely = (b.email ?? '').toLowerCase() === requesterEmail;

            return Number(bLikely) - Number(aLikely);
        });
    }, [candidates, search, requesterEmail]);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setSearch('');
                    setChosen(null);
                }

                onOpenChange(next);
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Which employee is this?</DialogTitle>
                    <DialogDescription>
                        Pick the record{' '}
                        <span className="font-medium text-foreground">
                            {request?.user?.full_name ?? 'this person'}
                        </span>{' '}
                        ({request?.user?.email}) should be linked to. They'll
                        get access to that record straight away.
                    </DialogDescription>
                </DialogHeader>

                <div className="relative">
                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search by name, number or email"
                        className="pl-9"
                        aria-label="Search employee records"
                    />
                </div>

                <div className="max-h-72 overflow-y-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                    {results.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
                            <UserRoundSearch className="size-6 text-muted-foreground" />
                            <p className="text-sm font-medium">
                                No matching records
                            </p>
                            <p className="max-w-xs text-sm text-muted-foreground">
                                Every employee already has app access, or none
                                match that search. Add the employee first, then
                                approve this request.
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-border">
                            {results.map((candidate) => {
                                const likely =
                                    (candidate.email ?? '').toLowerCase() ===
                                    requesterEmail;

                                return (
                                    <li key={candidate.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setChosen(candidate.id)
                                            }
                                            aria-pressed={
                                                chosen === candidate.id
                                            }
                                            className={`flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-muted/60 ${
                                                chosen === candidate.id
                                                    ? 'bg-[#0ABFBF]/10'
                                                    : ''
                                            }`}
                                        >
                                            <EmployeeAvatar
                                                name={candidate.full_name}
                                                initials={candidate.initials}
                                                photo={candidate.photo}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">
                                                    {candidate.full_name}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {candidate.employee_no}
                                                    {candidate.email
                                                        ? ` · ${candidate.email}`
                                                        : ''}
                                                </span>
                                            </span>
                                            {likely && (
                                                <span className="shrink-0 rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-2 py-0.5 text-xs font-medium text-[#067F7F]">
                                                    Email matches
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <div className="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={() => chosen !== null && onConfirm(chosen)}
                        disabled={chosen === null || processing}
                    >
                        {processing && <Spinner />}
                        Approve and link
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

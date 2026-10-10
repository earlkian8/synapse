import { Link } from '@inertiajs/react';
import { Check, Inbox, X } from 'lucide-react';
import { useState } from 'react';
import { TableCard } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
} from '@/components/modal';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { approveRequest, declineRequest } from '../api';
import { formatDate, TEXTAREA } from '../constants';
import { trainingRoutes } from '../routes';
import type { TrainingEnrollment } from '../types';

/**
 * Requests to join that wait for HR (ADR 0074), each with Approve and
 * Decline. On the overview every program's requests are listed, each naming
 * its program; on a program's page, only its own. Approving takes a seat if
 * one is left; declining asks for an optional note the person is shown.
 */
export function RequestsCard({
    requests,
    showProgram = false,
    seatsRemaining,
}: {
    requests: TrainingEnrollment[];
    /** Name each request's program (the overview lists them all). */
    showProgram?: boolean;
    /** Seats left on the program, when the card is for one program. */
    seatsRemaining?: number | null;
}) {
    const [busy, setBusy] = useState<number | null>(null);
    const [declining, setDeclining] = useState<TrainingEnrollment | null>(null);

    if (requests.length === 0) {
        return null;
    }

    const approve = (request: TrainingEnrollment) =>
        approveRequest(request.id, {
            onStart: () => setBusy(request.id),
            onFinish: () => setBusy(null),
        });

    return (
        <>
            <TableCard
                title="Requests to join"
                count={requests.length}
                description={
                    seatsRemaining === undefined
                        ? 'People who asked to join a program that needs approval.'
                        : seatsRemaining === null
                          ? 'This program has no seat limit.'
                          : seatsRemaining === 0
                            ? 'The program is full — free a seat or raise its capacity to approve anyone.'
                            : `${seatsRemaining} ${seatsRemaining === 1 ? 'seat' : 'seats'} left.`
                }
                className="border-amber-500/30 dark:border-amber-500/30"
            >
                <ul className="divide-y divide-border">
                    {requests.map((request) => {
                        const name = request.employee?.full_name ?? 'Unknown';

                        return (
                            <li
                                key={request.id}
                                className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex min-w-0 items-center gap-3">
                                    <PersonAvatar
                                        name={name}
                                        initials={
                                            request.employee?.initials ?? '?'
                                        }
                                        photo={request.employee?.photo}
                                        className="size-8"
                                    />
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {name}
                                            {showProgram && request.program && (
                                                <>
                                                    <span className="font-normal text-muted-foreground">
                                                        {' '}
                                                        asked to join{' '}
                                                    </span>
                                                    <Link
                                                        href={trainingRoutes.show(
                                                            request.program
                                                                .hashid,
                                                        )}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {request.program.name}
                                                    </Link>
                                                </>
                                            )}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {[
                                                request.employee?.position,
                                                request.employee?.department,
                                            ]
                                                .filter(Boolean)
                                                .join(', ') || '—'}
                                            {request.requested_on &&
                                                ` · asked ${formatDate(request.requested_on)}`}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={busy !== null}
                                        onClick={() => setDeclining(request)}
                                    >
                                        <X className="size-4" />
                                        Decline
                                    </Button>
                                    <Button
                                        size="sm"
                                        disabled={
                                            busy !== null ||
                                            seatsRemaining === 0
                                        }
                                        onClick={() => approve(request)}
                                    >
                                        {busy === request.id ? (
                                            <Spinner />
                                        ) : (
                                            <Check className="size-4" />
                                        )}
                                        Approve
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </TableCard>

            <DeclineModal
                request={declining}
                onOpenChange={(open) => !open && setDeclining(null)}
            />
        </>
    );
}

function DeclineModal({
    request,
    onOpenChange,
}: {
    request: TrainingEnrollment | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Modal open={request !== null} onOpenChange={onOpenChange}>
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 [&_svg]:size-5">
                            <Inbox />
                        </span>
                    }
                    title="Decline the request?"
                    description={`${request?.employee?.full_name ?? 'They'} will be told it was declined${request?.program ? ` for ${request.program.name}` : ''}.`}
                />
                {request && (
                    <DeclineForm
                        key={request.id}
                        request={request}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function DeclineForm({
    request,
    onDone,
}: {
    request: TrainingEnrollment;
    onDone: () => void;
}) {
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () =>
        declineRequest(request.id, note.trim() || null, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onDone,
            onError: setErrors,
        });

    return (
        <>
            <ModalBody>
                <FormField
                    label="Note for them"
                    hint="Optional. Shown with the decision."
                    error={errors.note}
                >
                    <textarea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        rows={3}
                        maxLength={1000}
                        placeholder="e.g. The next run in March suits your team better."
                        className={TEXTAREA}
                    />
                </FormField>
            </ModalBody>
            <ModalFooter>
                <Button
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    onClick={submit}
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    Decline
                </Button>
            </ModalFooter>
        </>
    );
}

import { Head, router, usePage } from '@inertiajs/react';
import { Check, Inbox, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { fetchCitation } from '@/features/awards/api';
import { AwardsNav } from '@/features/awards/components/awards-nav';
import { NominationViews } from '@/features/awards/components/nomination-views';
import { awardsRoutes } from '@/features/awards/routes';
import { formatDay } from '@/features/events/constants';
import { NOMINATION_STATUS } from '@/features/recognition/constants';
import type {
    Nomination,
    NominationQueuePageProps,
} from '@/features/recognition/types';

const textarea =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none';

/** Today on this computer, as a date input wants it. */
function today(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/**
 * Awards → Nominations (ADR 0071): what colleagues put forward, oldest first,
 * each approved into an award — citation editable, an AI draft on offer — or
 * turned down with a word to the nominator. Below, the last 90 days' decisions.
 */
export default function NominationQueue() {
    const { nominations, counts, ai_available } =
        usePage<NominationQueuePageProps>().props;
    const pending = nominations.filter((n) => n.status === 'pending');
    const decided = nominations.filter((n) => n.status !== 'pending');
    const [approving, setApproving] = useState<Nomination | null>(null);
    const [rejecting, setRejecting] = useState<Nomination | null>(null);

    return (
        <>
            <Head title="Nominations" />

            <PageBody>
                <PageHeader
                    title="Nominations"
                    description="Colleagues put each other forward. Approve to give the award and its points, or turn it down."
                    actions={
                        <AwardsNav
                            current="nominations"
                            pending={counts.pending}
                        />
                    }
                />

                <NominationViews
                    current="colleagues"
                    pending={counts.pending}
                />

                <section
                    aria-labelledby="waiting"
                    className="flex flex-col gap-3"
                >
                    <h2 id="waiting" className="text-base font-semibold">
                        Waiting for review{' '}
                        <span className="text-muted-foreground tabular-nums">
                            {pending.length}
                        </span>
                    </h2>

                    {pending.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-12 text-center">
                            <Inbox
                                className="size-8 text-muted-foreground"
                                aria-hidden
                            />
                            <p className="font-medium">Nothing waiting</p>
                            <p className="max-w-sm text-sm text-muted-foreground">
                                When someone nominates a colleague from the
                                recognition wall or the app, it lands here.
                            </p>
                        </div>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {pending.map((n) => (
                                <li
                                    key={n.id}
                                    className="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-4 md:flex-row md:items-start dark:border-sidebar-border"
                                >
                                    <PersonAvatar
                                        name={n.nominee?.name ?? '?'}
                                        initials={n.nominee?.initials ?? '?'}
                                        photo={n.nominee?.photo}
                                        className="size-11"
                                    />
                                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                        <p className="font-medium">
                                            {n.nominee?.name}
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                for{' '}
                                            </span>
                                            <span
                                                style={{
                                                    color:
                                                        n.award_type?.color ??
                                                        undefined,
                                                }}
                                            >
                                                {n.award_type?.name}
                                            </span>
                                            {n.award_type &&
                                                n.award_type.points > 0 && (
                                                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                        +{n.award_type.points}{' '}
                                                        points
                                                    </span>
                                                )}
                                        </p>
                                        {n.nominee?.position && (
                                            <p className="text-xs text-muted-foreground">
                                                {n.nominee.position}
                                            </p>
                                        )}
                                        <blockquote className="border-l-2 border-[#0ABFBF]/60 pl-3 text-sm leading-relaxed">
                                            {n.reason}
                                        </blockquote>
                                        <p className="text-xs text-muted-foreground">
                                            Nominated by{' '}
                                            {n.nominator ??
                                                'a former colleague'}{' '}
                                            · {formatDay(n.created_at)}
                                        </p>
                                    </div>
                                    {n.is_mine ? (
                                        <p className="shrink-0 text-xs text-muted-foreground md:max-w-40 md:text-right">
                                            You’re part of this nomination —
                                            another reviewer decides it.
                                        </p>
                                    ) : (
                                        <div className="flex shrink-0 gap-2 md:flex-col">
                                            <Button
                                                size="sm"
                                                onClick={() => setApproving(n)}
                                            >
                                                <Check className="size-4" />
                                                Approve
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setRejecting(n)}
                                            >
                                                <X className="size-4" />
                                                Turn down
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {decided.length > 0 && (
                    <section
                        aria-labelledby="decided"
                        className="flex flex-col gap-3"
                    >
                        <h2 id="decided" className="text-base font-semibold">
                            Decided in the last 90 days
                        </h2>
                        <ul className="divide-y divide-border rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                            {decided.map((n) => (
                                <li
                                    key={n.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
                                >
                                    <span className="min-w-0">
                                        <span className="font-medium">
                                            {n.nominee?.name}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {n.award_type?.name} · by{' '}
                                            {n.nominator ?? '—'}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-2 text-xs text-muted-foreground">
                                        {n.reviewer && `${n.reviewer}, `}
                                        {formatDay(
                                            n.reviewed_at ?? n.created_at,
                                        )}
                                        <Badge
                                            variant="outline"
                                            className={
                                                NOMINATION_STATUS[n.status]
                                                    .className
                                            }
                                        >
                                            {NOMINATION_STATUS[n.status].label}
                                        </Badge>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </PageBody>

            <ApproveDialog
                nomination={approving}
                aiAvailable={ai_available}
                onClose={() => setApproving(null)}
            />
            <RejectDialog
                nomination={rejecting}
                onClose={() => setRejecting(null)}
            />
        </>
    );
}

function ApproveDialog({
    nomination,
    aiAvailable,
    onClose,
}: {
    nomination: Nomination | null;
    aiAvailable: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={nomination !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Give {nomination?.nominee?.name} the{' '}
                        {nomination?.award_type?.name}
                    </DialogTitle>
                    <DialogDescription>
                        The citation is what they and the wall see. It starts as
                        the nominator’s words.
                    </DialogDescription>
                </DialogHeader>
                {nomination && (
                    <ApproveForm
                        key={nomination.id}
                        nomination={nomination}
                        aiAvailable={aiAvailable}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ApproveForm({
    nomination,
    aiAvailable,
    onDone,
}: {
    nomination: Nomination;
    aiAvailable: boolean;
    onDone: () => void;
}) {
    const [citation, setCitation] = useState(nomination.reason);
    const [awardedOn, setAwardedOn] = useState(today());
    const [drafting, setDrafting] = useState(false);
    const [draftError, setDraftError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const draft = async () => {
        if (!nomination.nominee || !nomination.award_type) {
            return;
        }

        setDrafting(true);
        setDraftError(null);
        const result = await fetchCitation(
            nomination.nominee.id,
            nomination.award_type.id,
        );
        setDrafting(false);

        if (result.available) {
            setCitation(result.citation);
        } else {
            setDraftError(result.reason);
        }
    };

    const approve = () =>
        router.post(
            awardsRoutes.approve(nomination.id),
            { citation, awarded_on: awardedOn },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
            },
        );

    return (
        <div className="flex flex-col gap-4">
            <div>
                <div className="mb-1.5 flex items-center justify-between">
                    <Label htmlFor="citation">Citation</Label>
                    {aiAvailable && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={draft}
                            disabled={drafting}
                        >
                            {drafting ? (
                                <Spinner />
                            ) : (
                                <Sparkles className="size-4" />
                            )}
                            Draft from their record
                        </Button>
                    )}
                </div>
                <textarea
                    id="citation"
                    value={citation}
                    onChange={(e) => setCitation(e.target.value)}
                    rows={5}
                    maxLength={2000}
                    className={textarea}
                />
                {draftError && (
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {draftError}
                    </p>
                )}
            </div>
            <div className="w-44">
                <Label htmlFor="awarded-on" className="mb-1.5 block">
                    Awarded on
                </Label>
                <Input
                    id="awarded-on"
                    type="date"
                    value={awardedOn}
                    max={today()}
                    onChange={(e) => setAwardedOn(e.target.value)}
                />
            </div>
            <DialogFooter>
                <Button
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    onClick={approve}
                    disabled={processing || citation.trim() === ''}
                >
                    {processing && <Spinner />}
                    Give the award
                </Button>
            </DialogFooter>
        </div>
    );
}

function RejectDialog({
    nomination,
    onClose,
}: {
    nomination: Nomination | null;
    onClose: () => void;
}) {
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);

    const reject = () =>
        nomination &&
        router.post(
            awardsRoutes.reject(nomination.id),
            { note },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setNote('');
                },
                onSuccess: onClose,
            },
        );

    return (
        <Dialog
            open={nomination !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Turn down this nomination?</DialogTitle>
                    <DialogDescription>
                        {nomination?.nominator ?? 'The nominator'} is told. A
                        short reason helps them nominate well next time.
                    </DialogDescription>
                </DialogHeader>
                <textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={3}
                    maxLength={1000}
                    placeholder="Why (optional) — e.g. they were recognised for this last month"
                    aria-label="Reason for the nominator"
                    className={textarea}
                />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={reject}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Turn down
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

NominationQueue.layout = {
    breadcrumbs: [
        { title: 'Awards & Recognition', href: '/awards' },
        { title: 'Nominations', href: '/awards/nominations' },
    ],
};

import { useQueryClient } from '@tanstack/react-query';
import {
    AppWindow,
    Building2,
    CalendarClock,
    Clock,
    Globe,
    Languages,
    Monitor,
    Palette,
    Smartphone,
    Tag,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link } from 'react-router';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { LoadError } from '@/components/page-states';
import {
    Fact,
    FactGrid,
    RecordDialog,
    RecordHeading,
    RecordSection,
    RecordSkeleton,
} from '@/components/record-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { ApiError } from '@/lib/api';
import { formatDateTime, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { updateProblemReport, useProblemReport } from '../api';
import type { ProblemReportDetail, ReportInput, ReportStatus } from '../api';
import { ImpactMeter, ReportStatusPill } from './report-pills';
import { Screenshot } from './screenshot';

const FORM_ID = 'problem-report-form';

const STATUSES: { value: ReportStatus; label: string; hint: string }[] = [
    { value: 'new', label: 'New', hint: 'Not picked up yet' },
    {
        value: 'investigating',
        label: 'Investigating',
        hint: 'Someone is on it',
    },
    { value: 'resolved', label: 'Resolved', hint: 'Fixed or answered' },
    { value: 'closed', label: 'Closed', hint: 'No change coming' },
];

const TEXTAREA =
    'flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive disabled:opacity-50';

/**
 * One problem report: what its sender wrote and what their device sent with
 * it, then the follow-up — where it stands, the reply they read, and a note
 * only admins read. Saving tells the sender when it moves on or the reply
 * changes, never about the note.
 */
export function ReportDialog({
    id,
    onClose,
}: {
    id: number | null;
    onClose: () => void;
}) {
    const report = useProblemReport(id);
    const record = report.data?.data;
    const [busy, setBusy] = useState(false);

    return (
        <RecordDialog
            open={id !== null}
            onOpenChange={(open) => !open && !busy && onClose()}
            title={record?.subject ?? 'Problem report'}
            description="What the sender reported, and how it is being followed up."
            header={
                record ? (
                    <RecordHeading
                        title={record.subject}
                        subtitle={
                            <>
                                <span className="font-medium text-foreground tabular-nums">
                                    {record.reference}
                                </span>{' '}
                                · {formatRelative(record.created_at)}
                                {record.organization
                                    ? ` · ${record.organization.name}`
                                    : ''}
                            </>
                        }
                        badges={
                            <ReportStatusPill
                                status={record.status}
                                label={record.status_label}
                            />
                        }
                    />
                ) : undefined
            }
            footer={
                record ? (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={busy}
                        >
                            Close
                        </Button>
                        <Button type="submit" form={FORM_ID} disabled={busy}>
                            {busy && <Spinner />}
                            Save follow-up
                        </Button>
                    </>
                ) : undefined
            }
        >
            {report.isError && (
                <LoadError
                    message={report.error.message}
                    onRetry={() => void report.refetch()}
                />
            )}

            {!record && !report.isError && <RecordSkeleton />}

            {record && (
                <div className="space-y-5">
                    <RecordSection
                        title="What they reported"
                        aside={
                            <ImpactMeter
                                impact={record.impact}
                                label={record.impact_label}
                                className="text-xs"
                            />
                        }
                    >
                        <div className="rounded-lg border border-sidebar-border/70 px-3.5 py-3 dark:border-sidebar-border">
                            <p className="text-xs font-medium text-muted-foreground">
                                {record.category_label}
                            </p>
                            <p className="mt-1.5 text-sm leading-relaxed whitespace-pre-wrap">
                                {record.description}
                            </p>
                        </div>
                        {record.attachments.length > 0 && (
                            <div className="flex gap-3 overflow-x-auto pb-1">
                                {record.attachments.map((attachment) => (
                                    <Screenshot
                                        key={attachment.id}
                                        reportId={record.id}
                                        attachment={attachment}
                                    />
                                ))}
                            </div>
                        )}
                    </RecordSection>

                    <RecordSection title="Sender and setting">
                        <SenderFacts record={record} />
                    </RecordSection>

                    {/* Re-keyed when the saved report changes, so the form
                        starts from what is stored, not a stale draft. */}
                    <FollowUp
                        key={record.updated_at ?? record.id}
                        record={record}
                        onBusy={setBusy}
                    />
                </div>
            )}
        </RecordDialog>
    );
}

function SenderFacts({ record }: { record: ProblemReportDetail }) {
    const { context } = record;

    return (
        <FactGrid columns={3}>
            <Fact
                icon={UserRound}
                label="Sent by"
                value={
                    record.reporter ? (
                        <Link
                            to={`/accounts?id=${record.reporter.id}`}
                            className="underline-offset-4 hover:underline"
                        >
                            {record.reporter.name}
                        </Link>
                    ) : (
                        'Deleted account'
                    )
                }
                title={record.reporter?.email}
            />
            <Fact
                icon={Building2}
                label="Organisation"
                value={
                    record.organization ? (
                        <Link
                            to={`/organisations?id=${record.organization.id}`}
                            className="underline-offset-4 hover:underline"
                        >
                            {record.organization.name}
                        </Link>
                    ) : (
                        'In no workspace'
                    )
                }
            />
            <Fact
                icon={CalendarClock}
                label="Sent"
                value={formatDateTime(record.created_at)}
            />
            <Fact
                icon={record.platform === 'web' ? Globe : Smartphone}
                label="From"
                value={
                    record.app_version
                        ? `${record.platform_label} ${record.app_version}`
                        : record.platform_label
                }
            />
            <Fact
                icon={AppWindow}
                label={record.platform === 'web' ? 'Page' : 'Part of the app'}
                value={record.page}
                title={record.page ?? undefined}
            />
            <Fact
                icon={Monitor}
                label="Window"
                value={
                    context.viewport
                        ? context.screen
                            ? `${context.viewport} (screen ${context.screen})`
                            : context.viewport
                        : undefined
                }
            />
            <Fact icon={Clock} label="Time zone" value={context.timezone} />
            <Fact icon={Languages} label="Language" value={context.language} />
            <Fact
                icon={Palette}
                label="Theme"
                value={
                    context.theme
                        ? context.theme === 'dark'
                            ? 'Dark'
                            : 'Light'
                        : undefined
                }
            />
            <Fact
                icon={Tag}
                label={record.platform === 'web' ? 'Browser' : 'Device'}
                value={record.device}
                wide
            />
        </FactGrid>
    );
}

function FollowUp({
    record,
    onBusy,
}: {
    record: ProblemReportDetail;
    onBusy: (busy: boolean) => void;
}) {
    const queryClient = useQueryClient();
    const [status, setStatus] = useState<ReportStatus>(record.status);
    const [reply, setReply] = useState(record.reply ?? '');
    const [note, setNote] = useState(record.internal_note ?? '');
    const [error, setError] = useState<ApiError | null>(null);

    const replyChanged = reply.trim() !== (record.reply ?? '');
    const tellsSender =
        (status !== record.status && status !== 'new') ||
        (replyChanged && reply.trim() !== '');

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        onBusy(true);
        setError(null);

        const input: ReportInput = { status, reply, internal_note: note };

        try {
            const result = await updateProblemReport(record.id, input);

            queryClient.setQueryData(['problem-report', record.id], {
                data: result.data,
            });
            await Promise.all([
                queryClient.invalidateQueries({
                    queryKey: ['problem-reports'],
                }),
                queryClient.invalidateQueries({
                    queryKey: ['problem-report-counts'],
                }),
                queryClient.invalidateQueries({ queryKey: ['overview'] }),
                queryClient.invalidateQueries({ queryKey: ['activity'] }),
            ]);
            toast.success(
                tellsSender
                    ? `${result.message} The sender was notified.`
                    : result.message,
            );
        } catch (failure) {
            setError(failure instanceof ApiError ? failure : null);
        } finally {
            onBusy(false);
        }
    };

    return (
        <RecordSection
            title="Follow-up"
            aside={
                record.handled_by
                    ? `Last saved by ${record.handled_by}, ${formatRelative(record.updated_at)}`
                    : undefined
            }
        >
            <form
                id={FORM_ID}
                onSubmit={submit}
                className="grid gap-4 rounded-lg border border-sidebar-border/70 p-3.5 dark:border-sidebar-border"
                noValidate
            >
                <fieldset className="grid gap-1.5">
                    <legend className="mb-1.5 text-sm font-medium">
                        Status
                    </legend>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {STATUSES.map((option) => (
                            <label
                                key={option.value}
                                className={cn(
                                    'flex cursor-pointer flex-col rounded-lg border px-3 py-2 transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                    status === option.value
                                        ? 'border-[#0ABFBF] bg-[#0ABFBF]/6'
                                        : 'border-border hover:bg-muted/40',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="status"
                                    value={option.value}
                                    checked={status === option.value}
                                    onChange={() => setStatus(option.value)}
                                    className="sr-only"
                                />
                                <span className="text-sm font-medium">
                                    {option.label}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {option.hint}
                                </span>
                            </label>
                        ))}
                    </div>
                    <InputError message={error?.field('status')} />
                </fieldset>

                <div className="grid gap-1.5">
                    <Label htmlFor="report-reply">Reply to the sender</Label>
                    <textarea
                        id="report-reply"
                        value={reply}
                        onChange={(event) => setReply(event.target.value)}
                        rows={3}
                        maxLength={2000}
                        aria-invalid={error?.field('reply') ? true : undefined}
                        placeholder="What you found, what was done, or what they can do meanwhile."
                        className={TEXTAREA}
                    />
                    <p className="text-xs text-muted-foreground">
                        They read it on their report in the ERP or the app.
                        {record.replied_at
                            ? ` Last replied ${formatDateTime(record.replied_at)}.`
                            : ''}
                    </p>
                    <InputError message={error?.field('reply')} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="report-note">Internal note</Label>
                    <textarea
                        id="report-note"
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        rows={2}
                        maxLength={2000}
                        aria-invalid={
                            error?.field('internal_note') ? true : undefined
                        }
                        placeholder="Cause, a linked issue, who is on it."
                        className={TEXTAREA}
                    />
                    <p className="text-xs text-muted-foreground">
                        Only admins see this.
                    </p>
                    <InputError message={error?.field('internal_note')} />
                </div>

                {tellsSender && (
                    <p className="rounded-md bg-[#0ABFBF]/8 px-3 py-2 text-xs text-[#08767c] dark:text-[#3dd6d6]">
                        Saving notifies {record.reporter?.name ?? 'the sender'}{' '}
                        in the ERP, and by email or push if they get those.
                    </p>
                )}

                {error &&
                    !error.field('status') &&
                    !error.field('reply') &&
                    !error.field('internal_note') && (
                        <InputError message={error.message} />
                    )}
            </form>
        </RecordSection>
    );
}

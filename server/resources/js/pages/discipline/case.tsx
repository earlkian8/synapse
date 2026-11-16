import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    Download,
    FileSignature,
    FileText,
    Gavel,
    MessageSquareText,
    Paperclip,
    PauseCircle,
    Trash2,
    Undo2,
    Upload,
    UserRoundMinus,
    Users,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    GravityMark,
    StandingBadge,
    StatusBadge,
} from '@/features/discipline/components/case-badges';
import {
    DecisionDialog,
    ExplanationDialog,
    HearingDialog,
    NoticeDialog,
    PreventiveDialog,
    RecordHearingDialog,
    WithdrawDialog,
} from '@/features/discipline/components/case-dialogs';
import { DueProcess } from '@/features/discipline/components/due-process';
import { calendarDate, dateTime } from '@/features/discipline/constants';
import { disciplineRoutes } from '@/features/discipline/routes';
import type { CasePageProps } from '@/features/discipline/types';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';

type Dialog =
    | 'notice'
    | 'explanation'
    | 'hearing'
    | 'record'
    | 'preventive'
    | 'decision'
    | 'withdraw'
    | null;

/**
 * One disciplinary case (ADR 0105): the next step of due process and what
 * stands in its way, then the record of each step — the incident, the notice to
 * explain, the explanation, the hearing, the decision — and the evidence. The
 * employee and where they stand sit beside it, with the steps in order.
 */
export default function DisciplineCase() {
    const props = usePage<CasePageProps>().props;
    const {
        case: c,
        record,
        suggestion,
        blockers,
        options,
        today,
        can,
    } = props;
    const timeZone = useOrganizationTimeZone();
    const [dialog, setDialog] = useState<Dialog>(null);
    const upload = useRef<HTMLInputElement>(null);

    const manage = can.manage && !can.own;
    const open = ['reported', 'notice_issued', 'explained', 'hearing'].includes(
        c.status,
    );
    const set = (value: Dialog) => () => setDialog(value);
    const dialogProps = (value: Exclude<Dialog, null>) => ({
        case: c,
        open: dialog === value,
        onOpenChange: (next: boolean) => setDialog(next ? value : null),
    });

    const next = nextStep(c, blockers);

    return (
        <>
            <Head title={`${c.reference}, ${c.employee?.name ?? 'Case'}`} />

            <PageBody>
                <PageHeader
                    title={c.reference}
                    description={`${c.employee?.name ?? 'Employee'}${c.offense ? `, ${c.offense.code} ${c.offense.title}` : ''}`}
                    badges={
                        <StatusBadge
                            status={c.status}
                            overdue={open && c.reply_overdue}
                        />
                    }
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {c.notice_issued_at && (
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={disciplineRoutes.noticePdf(
                                            c.hashid,
                                        )}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <FileText className="size-4" />
                                        Notice to explain
                                    </a>
                                </Button>
                            )}
                            {c.decided_at && (
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={disciplineRoutes.decisionPdf(
                                            c.hashid,
                                        )}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <FileText className="size-4" />
                                        Notice of decision
                                    </a>
                                </Button>
                            )}
                        </div>
                    }
                />

                {can.own && (
                    <p className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
                        This case is about you. Somebody else in HR serves its
                        notices and decides it.
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="min-w-0 space-y-5">
                        {manage && (open || c.status === 'decided') && (
                            <section
                                aria-label="Next step"
                                className="rounded-xl border border-[#0ABFBF]/40 bg-[#0ABFBF]/[0.04] p-4 sm:p-5"
                            >
                                <p className="text-sm font-semibold">
                                    {next.title}
                                </p>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {next.body}
                                </p>
                                {blockers.length > 0 && c.notice_issued_at && (
                                    <ul className="mt-3 space-y-1.5">
                                        {blockers.map((blocker) => (
                                            <li
                                                key={blocker}
                                                className="flex gap-2 text-sm"
                                            >
                                                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                                {blocker}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {c.status === 'reported' && (
                                        <Button
                                            size="sm"
                                            onClick={set('notice')}
                                        >
                                            <FileSignature className="size-4" />
                                            Serve a notice to explain
                                        </Button>
                                    )}
                                    {open &&
                                        c.notice_issued_at &&
                                        blockers.length === 0 && (
                                            <Button
                                                size="sm"
                                                onClick={set('decision')}
                                            >
                                                <Gavel className="size-4" />
                                                Decide the case
                                            </Button>
                                        )}
                                    {open &&
                                        c.notice_issued_at &&
                                        c.hearing_at &&
                                        !c.hearing_held_at && (
                                            <Button
                                                size="sm"
                                                variant={
                                                    blockers.length > 0
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                onClick={set('record')}
                                            >
                                                <Users className="size-4" />
                                                Record the hearing
                                            </Button>
                                        )}
                                    {open && c.notice_issued_at && (
                                        <Button
                                            size="sm"
                                            variant={
                                                c.hearing_requested &&
                                                !c.hearing_at
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={set('hearing')}
                                        >
                                            <CalendarClock className="size-4" />
                                            {c.hearing_at
                                                ? 'Move the hearing'
                                                : 'Set a hearing'}
                                        </Button>
                                    )}
                                    {open &&
                                        c.notice_issued_at &&
                                        !c.explained_at && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={set('explanation')}
                                            >
                                                <MessageSquareText className="size-4" />
                                                Record a paper explanation
                                            </Button>
                                        )}
                                    {open && c.notice_issued_at && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={set('preventive')}
                                        >
                                            <PauseCircle className="size-4" />
                                            Preventive suspension
                                        </Button>
                                    )}
                                    {open && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={set('withdraw')}
                                        >
                                            <Undo2 className="size-4" />
                                            Withdraw
                                        </Button>
                                    )}
                                    {c.status === 'decided' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.patch(
                                                    disciplineRoutes.close(
                                                        c.hashid,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Close the case
                                        </Button>
                                    )}
                                    {c.status === 'decided' &&
                                        c.penalty === 'dismissal' &&
                                        can.offboard &&
                                        c.employee && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                asChild
                                            >
                                                <Link href="/offboarding">
                                                    <UserRoundMinus className="size-4" />
                                                    Start their exit in
                                                    Offboarding
                                                </Link>
                                            </Button>
                                        )}
                                </div>
                            </section>
                        )}

                        <Block
                            title="The incident"
                            meta={`Reported${c.reported_by ? ` by ${c.reported_by}` : ''}`}
                        >
                            <Facts
                                items={[
                                    [
                                        'When',
                                        `${calendarDate(c.incident_date)}${c.incident_time ? `, ${c.incident_time}` : ''}`,
                                    ],
                                    ['Where', c.location ?? '—'],
                                    [
                                        'Offense',
                                        c.offense ? (
                                            <span className="inline-flex items-center gap-2">
                                                {c.offense.code}{' '}
                                                {c.offense.title}{' '}
                                                <GravityMark
                                                    gravity={c.offense.gravity}
                                                />
                                            </span>
                                        ) : (
                                            'Not set yet'
                                        ),
                                    ],
                                ]}
                            />
                            <Prose>{c.narrative}</Prose>
                            {c.witnesses && (
                                <p className="text-sm">
                                    <span className="text-muted-foreground">
                                        Witnesses, for HR only:{' '}
                                    </span>
                                    {c.witnesses}
                                </p>
                            )}
                        </Block>

                        {c.notice_issued_at && (
                            <Block
                                title="Notice to explain"
                                meta={`Served ${dateTime(c.notice_issued_at, timeZone)}${c.notice_issued_by ? ` by ${c.notice_issued_by}` : ''}`}
                            >
                                <Prose>{c.notice_grounds}</Prose>
                                <Facts
                                    items={[
                                        [
                                            'Answer due',
                                            calendarDate(c.reply_due_on),
                                        ],
                                        [
                                            'Received',
                                            c.notice_received_at
                                                ? dateTime(
                                                      c.notice_received_at,
                                                      timeZone,
                                                  )
                                                : 'Not acknowledged yet',
                                        ],
                                        ...(c.offense?.ground
                                            ? ([
                                                  ['Ground', c.offense.ground],
                                              ] as [string, string][])
                                            : []),
                                        ...(c.preventive_from
                                            ? ([
                                                  [
                                                      'Preventive suspension',
                                                      `${calendarDate(c.preventive_from)} to ${calendarDate(c.preventive_to)}`,
                                                  ],
                                              ] as [string, string][])
                                            : []),
                                    ]}
                                />
                            </Block>
                        )}

                        {c.explained_at && (
                            <Block
                                title="Their explanation"
                                meta={`${c.explanation_by_hr ? 'Handed in on paper, recorded ' : 'Sent '}${dateTime(c.explained_at, timeZone)}${c.hearing_requested ? '. They ask for a hearing.' : ''}`}
                            >
                                <Prose>{c.explanation}</Prose>
                            </Block>
                        )}

                        {c.hearing_at && (
                            <Block
                                title="Hearing"
                                meta={`${dateTime(c.hearing_at, timeZone)}${c.hearing_place ? `, ${c.hearing_place}` : ''}`}
                            >
                                {c.hearing_held_at ? (
                                    <>
                                        <p className="text-sm text-muted-foreground">
                                            {c.hearing_attended
                                                ? 'They came.'
                                                : 'They didn’t come.'}
                                        </p>
                                        <Prose>{c.hearing_notes}</Prose>
                                    </>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Not held yet.
                                    </p>
                                )}
                            </Block>
                        )}

                        {c.decided_at && (
                            <Block
                                title="Decision"
                                meta={`${dateTime(c.decided_at, timeZone)}${c.decided_by ? ` by ${c.decided_by}` : ''}`}
                            >
                                <p className="text-base font-semibold">
                                    {c.finding === 'upheld'
                                        ? `Upheld: ${c.penalty_label}${c.penalty === 'suspension' ? `, ${c.suspension_days} days from ${calendarDate(c.penalty_from)} to ${calendarDate(c.penalty_to)}` : c.penalty === 'dismissal' ? `, effective ${calendarDate(c.penalty_from)}` : ''}`
                                        : 'Not upheld'}
                                </p>
                                {c.finding === 'upheld' &&
                                    c.suggested_penalty &&
                                    c.suggested_penalty !== c.penalty && (
                                        <p className="text-xs text-muted-foreground">
                                            The code of conduct’s step was{' '}
                                            {options.penalties[
                                                c.suggested_penalty
                                            ].toLowerCase()}
                                            .
                                        </p>
                                    )}
                                <Prose>{c.decision}</Prose>
                                <Facts
                                    items={[
                                        [
                                            'Received',
                                            c.decision_received_at
                                                ? dateTime(
                                                      c.decision_received_at,
                                                      timeZone,
                                                  )
                                                : 'Not acknowledged yet',
                                        ],
                                    ]}
                                />
                            </Block>
                        )}

                        {c.status === 'withdrawn' && (
                            <Block
                                title="Withdrawn"
                                meta={dateTime(c.closed_at, timeZone)}
                            >
                                <Prose>{c.withdrawn_reason}</Prose>
                            </Block>
                        )}

                        <Block
                            title="Evidence"
                            meta="Only HR sees these files."
                            action={
                                manage ? (
                                    <>
                                        <input
                                            ref={upload}
                                            type="file"
                                            className="sr-only"
                                            accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx"
                                            onChange={(event) => {
                                                const file =
                                                    event.target.files?.[0];

                                                if (file) {
                                                    router.post(
                                                        disciplineRoutes.attach(
                                                            c.hashid,
                                                        ),
                                                        { file },
                                                        {
                                                            forceFormData: true,
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }

                                                event.target.value = '';
                                            }}
                                        />
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                upload.current?.click()
                                            }
                                        >
                                            <Upload className="size-4" />
                                            Add a file
                                        </Button>
                                    </>
                                ) : undefined
                            }
                        >
                            {c.attachments.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No files yet. Photos, written statements and
                                    records go here.
                                </p>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {c.attachments.map((file) => (
                                        <li
                                            key={file.id}
                                            className="flex items-center gap-3 px-3 py-2 text-sm"
                                        >
                                            <Paperclip className="size-4 shrink-0 text-muted-foreground" />
                                            <a
                                                href={file.url ?? '#'}
                                                target="_blank"
                                                rel="noopener"
                                                className="min-w-0 flex-1 truncate font-medium hover:underline"
                                            >
                                                {file.name}
                                            </a>
                                            <span className="hidden text-xs text-muted-foreground sm:inline">
                                                {Math.max(
                                                    1,
                                                    Math.round(
                                                        file.size / 1024,
                                                    ),
                                                )}{' '}
                                                KB
                                                {file.uploaded_by
                                                    ? `, ${file.uploaded_by}`
                                                    : ''}
                                            </span>
                                            <a
                                                href={file.url ?? '#'}
                                                download
                                                className="text-muted-foreground hover:text-foreground"
                                                aria-label={`Download ${file.name}`}
                                            >
                                                <Download className="size-4" />
                                            </a>
                                            {manage && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.delete(
                                                            disciplineRoutes.detach(
                                                                file.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    className="text-muted-foreground hover:text-rose-600"
                                                    aria-label={`Remove ${file.name}`}
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Block>
                    </div>

                    <aside className="space-y-5">
                        {c.employee && (
                            <section className="rounded-xl border bg-card p-4">
                                <div className="flex items-center gap-3">
                                    <PersonAvatar
                                        name={c.employee.name}
                                        initials={c.employee.initials}
                                        photo={c.employee.photo}
                                        className="size-11"
                                    />
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {c.employee.name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {[
                                                c.employee.employee_no,
                                                c.employee.position,
                                                c.employee.department,
                                            ]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </p>
                                    </div>
                                </div>
                                {record && (
                                    <div className="mt-3 space-y-2 border-t pt-3">
                                        <StandingBadge
                                            standing={record.standing}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {record.upheld} upheld,{' '}
                                            {record.cleared} cleared,{' '}
                                            {record.open} open.
                                        </p>
                                        <Link
                                            href={disciplineRoutes.record(
                                                c.employee.id,
                                            )}
                                            className="text-sm font-medium text-[#08767c] hover:underline dark:text-[#0ABFBF]"
                                        >
                                            Open their conduct record
                                        </Link>
                                    </div>
                                )}
                            </section>
                        )}

                        <section className="rounded-xl border bg-card p-4">
                            <h2 className="mb-3 text-sm font-semibold">
                                Due process
                            </h2>
                            <DueProcess case={c} today={today} viewer="hr" />
                        </section>
                    </aside>
                </div>
            </PageBody>

            {manage && (
                <>
                    <NoticeDialog
                        {...dialogProps('notice')}
                        offenses={options.offenses}
                        earliestReply={options.earliest_reply}
                    />
                    <ExplanationDialog {...dialogProps('explanation')} />
                    <HearingDialog {...dialogProps('hearing')} today={today} />
                    <RecordHearingDialog {...dialogProps('record')} />
                    <PreventiveDialog
                        {...dialogProps('preventive')}
                        today={today}
                        maxDays={options.max_preventive_days}
                    />
                    <DecisionDialog
                        {...dialogProps('decision')}
                        suggestion={suggestion}
                        today={today}
                        maxSuspension={options.max_suspension_days}
                    />
                    <WithdrawDialog {...dialogProps('withdraw')} />
                </>
            )}
        </>
    );
}

/** What due process asks for next, in a line. */
function nextStep(
    c: CasePageProps['case'],
    blockers: string[],
): { title: string; body: string } {
    if (c.status === 'reported') {
        return {
            title: 'Read the report, then decide whether to charge',
            body: 'If it should go further, serve a notice to explain: the charge, the facts, and at least five calendar days to answer. Otherwise, withdraw it.',
        };
    }

    if (c.status === 'decided') {
        return {
            title: 'Decided',
            body: c.decision_received_at
                ? 'They have acknowledged the decision. Close the case once any penalty has run.'
                : 'Waiting for them to acknowledge the notice of decision. A signed paper copy does too.',
        };
    }

    if (blockers.length > 0) {
        return {
            title: 'Not ready to decide yet',
            body: 'Due process asks for these first:',
        };
    }

    return {
        title: 'Ready to decide',
        body: c.explained_at
            ? 'Their explanation is in. Weigh it with the evidence, then serve the decision.'
            : 'The time to answer ran out without an answer, so the right was waived. Decide on the evidence.',
    };
}

function Block({
    title,
    meta,
    action,
    children,
}: {
    title: string;
    meta?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-xl border bg-card p-4 sm:p-5">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {meta && (
                        <p className="text-xs text-muted-foreground">{meta}</p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Prose({ children }: { children: ReactNode }) {
    return (
        <p className="max-w-[72ch] text-sm leading-relaxed whitespace-pre-line">
            {children}
        </p>
    );
}

function Facts({ items }: { items: [string, ReactNode][] }) {
    return (
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            {items.map(([label, value]) => (
                <div key={label} className="min-w-0">
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd>{value}</dd>
                </div>
            ))}
        </dl>
    );
}

DisciplineCase.layout = {
    breadcrumbs: [
        { title: 'Discipline', href: disciplineRoutes.index },
        { title: 'Case', href: '#' },
    ],
};

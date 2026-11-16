import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, FileText, Send } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { StatusBadge } from '@/features/discipline/components/case-badges';
import { DueProcess } from '@/features/discipline/components/due-process';
import { calendarDate, dateTime } from '@/features/discipline/constants';
import { disciplineRoutes } from '@/features/discipline/routes';
import type { CaseDetail } from '@/features/discipline/types';
import { TEXTAREA } from '@/features/training/constants';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';

type Props = { case: CaseDetail };

/**
 * One notice served on you (ADR 0105): the charge and the facts, the day your
 * answer is due, a place to write it and to ask for a hearing, and — once
 * decided — the decision and its reasons. Saying you received a notice is your
 * acknowledgement that it was served; it doesn't mean you agree with it.
 */
export default function MyCase() {
    const { case: c } = usePage<Props>().props;
    const timeZone = useOrganizationTimeZone();
    const today = new Date().toISOString().slice(0, 10);
    const open = ['notice_issued', 'explained', 'hearing'].includes(c.status);
    const hearingId = useId();

    const form = useForm({
        explanation: c.explanation ?? '',
        hearing: c.hearing_requested,
    });

    const acknowledge = (notice: 'notice' | 'decision') =>
        router.patch(
            disciplineRoutes.myAcknowledge(c.hashid),
            { notice },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={c.reference} />

            <PageBody>
                <PageHeader
                    title={c.offense?.title ?? 'Notice to explain'}
                    description={`${c.reference}, about the incident of ${calendarDate(c.incident_date)}`}
                    badges={<StatusBadge status={c.status} />}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="outline" asChild>
                                <a
                                    href={disciplineRoutes.myNoticePdf(
                                        c.hashid,
                                    )}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <FileText className="size-4" />
                                    Notice to explain
                                </a>
                            </Button>
                            {c.decided_at && (
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={disciplineRoutes.myDecisionPdf(
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

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <div className="min-w-0 space-y-5">
                        {c.decided_at && (
                            <Panel
                                title="The decision"
                                meta={dateTime(c.decided_at, timeZone)}
                            >
                                <p className="text-base font-semibold">
                                    {c.finding === 'upheld'
                                        ? `The charge is upheld: ${c.penalty_label}${c.penalty === 'suspension' ? `, ${c.suspension_days} days from ${calendarDate(c.penalty_from)} to ${calendarDate(c.penalty_to)}` : c.penalty === 'dismissal' ? `, effective ${calendarDate(c.penalty_from)}` : ''}.`
                                        : 'The charge is not upheld. No penalty is imposed.'}
                                </p>
                                <Prose>{c.decision}</Prose>
                                <Acknowledgement
                                    at={c.decision_received_at}
                                    onAcknowledge={() =>
                                        acknowledge('decision')
                                    }
                                    timeZone={timeZone}
                                />
                            </Panel>
                        )}

                        <Panel
                            title="The charge"
                            meta={`Served ${dateTime(c.notice_issued_at, timeZone)}`}
                        >
                            <Prose>{c.notice_grounds}</Prose>
                            {c.offense?.ground && (
                                <p className="text-xs text-muted-foreground">
                                    {c.offense.code}, {c.offense.ground}
                                </p>
                            )}
                            <div className="rounded-lg bg-muted/40 p-3">
                                <p className="mb-1 text-xs font-medium text-muted-foreground">
                                    What is reported to have happened,{' '}
                                    {calendarDate(c.incident_date)}
                                    {c.location ? `, ${c.location}` : ''}
                                </p>
                                <Prose>{c.narrative}</Prose>
                            </div>
                            {c.preventive_from && (
                                <p className="text-sm">
                                    You are on preventive suspension from{' '}
                                    {calendarDate(c.preventive_from)} to{' '}
                                    {calendarDate(c.preventive_to)} while this
                                    is heard. It is not a penalty.
                                </p>
                            )}
                            <Acknowledgement
                                at={c.notice_received_at}
                                onAcknowledge={() => acknowledge('notice')}
                                timeZone={timeZone}
                            />
                        </Panel>

                        {c.hearing_at && (
                            <Panel
                                title="Hearing"
                                meta={`${dateTime(c.hearing_at, timeZone)}${c.hearing_place ? `, ${c.hearing_place}` : ''}`}
                            >
                                <p className="text-sm">
                                    {c.hearing_held_at
                                        ? 'The hearing has been held.'
                                        : 'You can explain in person, present evidence and bring a representative, such as a lawyer or a union officer.'}
                                </p>
                            </Panel>
                        )}

                        <Panel
                            title="Your explanation"
                            meta={
                                c.explained_at
                                    ? `Sent ${dateTime(c.explained_at, timeZone)}`
                                    : c.reply_overdue
                                      ? `It was due ${calendarDate(c.reply_due_on)}. HR still reads it until the case is decided.`
                                      : `Due ${calendarDate(c.reply_due_on)}`
                            }
                        >
                            {open ? (
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        form.post(
                                            disciplineRoutes.myExplain(
                                                c.hashid,
                                            ),
                                            { preserveScroll: true },
                                        );
                                    }}
                                    className="space-y-4"
                                >
                                    <FormField
                                        label="In your own words"
                                        error={form.errors.explanation}
                                        hint="Say what happened from your side, and anything that explains or excuses it. You can change it until the case is decided."
                                    >
                                        <textarea
                                            value={form.data.explanation}
                                            onChange={(event) =>
                                                form.setData(
                                                    'explanation',
                                                    event.target.value,
                                                )
                                            }
                                            rows={8}
                                            maxLength={10000}
                                            className={TEXTAREA}
                                        />
                                    </FormField>
                                    <label
                                        htmlFor={hearingId}
                                        className="flex items-start gap-2.5 text-sm"
                                    >
                                        <Checkbox
                                            id={hearingId}
                                            checked={form.data.hearing}
                                            onCheckedChange={(value) =>
                                                form.setData(
                                                    'hearing',
                                                    value === true,
                                                )
                                            }
                                            disabled={c.hearing_requested}
                                        />
                                        <span>
                                            I ask for a hearing, to explain in
                                            person
                                            <span className="block text-xs text-muted-foreground">
                                                HR has to hold it before
                                                deciding.
                                            </span>
                                        </span>
                                    </label>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {form.processing ? (
                                            <Spinner />
                                        ) : (
                                            <Send className="size-4" />
                                        )}
                                        {c.explained_at
                                            ? 'Send the changes'
                                            : 'Send my explanation'}
                                    </Button>
                                </form>
                            ) : c.explanation ? (
                                <Prose>{c.explanation}</Prose>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    You didn’t send one.
                                </p>
                            )}
                        </Panel>
                    </div>

                    <aside>
                        <section className="rounded-xl border bg-card p-4">
                            <h2 className="mb-3 text-sm font-semibold">
                                Where it stands
                            </h2>
                            <DueProcess
                                case={c}
                                today={today}
                                viewer="employee"
                            />
                        </section>
                    </aside>
                </div>
            </PageBody>
        </>
    );
}

function Acknowledgement({
    at,
    onAcknowledge,
    timeZone,
}: {
    at: string | null;
    onAcknowledge: () => void;
    timeZone?: string;
}) {
    return at ? (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <CheckCircle2 className="size-3.5 text-emerald-600" />
            You said you received it {dateTime(at, timeZone)}
        </p>
    ) : (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2">
            <p className="flex-1 text-sm">
                Let HR know you’ve received this. It doesn’t mean you agree.
            </p>
            <Button size="sm" onClick={onAcknowledge}>
                I’ve received it
            </Button>
        </div>
    );
}

function Panel({
    title,
    meta,
    children,
}: {
    title: string;
    meta?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-xl border bg-card p-4 sm:p-5">
            <div>
                <h2 className="text-sm font-semibold">{title}</h2>
                {meta && (
                    <p className="text-xs text-muted-foreground">{meta}</p>
                )}
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

MyCase.layout = {
    breadcrumbs: [
        { title: 'My notices', href: disciplineRoutes.me },
        { title: 'Notice', href: '#' },
    ],
};

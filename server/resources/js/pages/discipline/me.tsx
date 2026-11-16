import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Inbox } from 'lucide-react';
import { PageBody, PageHeader } from '@/components/data-table';
import { StatusBadge } from '@/features/discipline/components/case-badges';
import { ConductRecordCard } from '@/features/discipline/components/conduct-record-card';
import { DisciplineNav } from '@/features/discipline/components/discipline-nav';
import { calendarDate } from '@/features/discipline/constants';
import { disciplineRoutes } from '@/features/discipline/routes';
import type { CaseSummary, ConductRecord } from '@/features/discipline/types';

type Props = {
    record: ConductRecord | null;
    cases: CaseSummary[];
    linked: boolean;
};

/**
 * My notices (ADR 0105): the notices served on you, what each asks of you and
 * by when, and where you stand.
 */
export default function MyNotices() {
    const { record, cases, linked } = usePage<Props>().props;

    return (
        <>
            <Head title="My notices" />

            <PageBody>
                <PageHeader
                    title="My notices"
                    description="Notices HR has served on you. You can read each one, say you received it, and answer in writing."
                    actions={<DisciplineNav current="me" />}
                />

                {!linked ? (
                    <p className="rounded-xl border bg-card px-5 py-8 text-center text-sm text-muted-foreground">
                        Your account isn’t linked to an employee record. Ask HR
                        to link it.
                    </p>
                ) : (
                    <>
                        {record && (
                            <ConductRecordCard
                                record={record}
                                caseHref={disciplineRoutes.myCase}
                            />
                        )}

                        {cases.length === 0 ? (
                            <section className="flex flex-col items-center gap-3 rounded-xl border bg-card px-6 py-12 text-center">
                                <span className="flex size-11 items-center justify-center rounded-xl bg-[#0ABFBF]/10 text-[#08767c] dark:text-[#0ABFBF]">
                                    <Inbox className="size-5" />
                                </span>
                                <p className="font-medium">No notices</p>
                                <p className="max-w-md text-sm text-muted-foreground">
                                    If HR ever serves you a notice to explain,
                                    it arrives here and you’re told at once.
                                    You’ll have at least five days to answer.
                                </p>
                            </section>
                        ) : (
                            <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                                {cases.map((item) => {
                                    const waiting =
                                        item.status === 'notice_issued' &&
                                        !item.explained &&
                                        !item.reply_overdue;

                                    return (
                                        <li key={item.hashid}>
                                            <Link
                                                href={disciplineRoutes.myCase(
                                                    item.hashid,
                                                )}
                                                className="flex items-center gap-4 px-4 py-3.5 hover:bg-muted/40 sm:px-5"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-medium">
                                                        {item.offense?.title ??
                                                            'Notice to explain'}
                                                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                            {item.reference}
                                                        </span>
                                                    </p>
                                                    <p
                                                        className={
                                                            waiting
                                                                ? 'text-sm text-amber-700 dark:text-amber-300'
                                                                : 'text-sm text-muted-foreground'
                                                        }
                                                    >
                                                        {waiting
                                                            ? `Answer by ${calendarDate(item.reply_due_on)}`
                                                            : `Incident of ${calendarDate(item.incident_date)}`}
                                                    </p>
                                                </div>
                                                <StatusBadge
                                                    status={item.status}
                                                />
                                                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </>
                )}
            </PageBody>
        </>
    );
}

MyNotices.layout = {
    breadcrumbs: [{ title: 'My notices', href: disciplineRoutes.me }],
};

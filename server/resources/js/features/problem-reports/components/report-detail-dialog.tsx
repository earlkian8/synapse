import { ImageIcon } from 'lucide-react';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalSection,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { CATEGORIES } from '../constants';
import { formatDate, formatDateTime } from '../format';
import type { ProblemReport } from '../types';
import { ReportProgress } from './report-progress';
import { ReportStatusPill } from './report-status-pill';

/**
 * One of one's own reports: where it stands, the SYNAPSE team's reply, and
 * what was sent. Read-only — a new problem is a new report.
 */
export function ReportDetailDialog({
    report,
    onClose,
}: {
    report: ProblemReport | null;
    onClose: () => void;
}) {
    return (
        <Modal
            open={report !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <ModalContent size="lg">
                {report && <Detail report={report} onClose={onClose} />}
            </ModalContent>
        </Modal>
    );
}

function Detail({
    report,
    onClose,
}: {
    report: ProblemReport;
    onClose: () => void;
}) {
    const Icon =
        CATEGORIES.find((option) => option.value === report.category)?.icon ??
        ImageIcon;

    const facts: [string, string][] = [
        ['Kind', report.category_label],
        ['Effect on your work', report.impact_label],
        [
            'Sent from',
            report.page
                ? `${report.platform_label} · ${report.page}`
                : report.platform_label,
        ],
        ['Sent', formatDateTime(report.created_at)],
    ];

    return (
        <>
            <ModalHeader
                icon={
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#0ABFBF]/10 text-[#0A9E9E] dark:text-[#0ABFBF] [&_svg]:size-5">
                        <Icon />
                    </span>
                }
                // The whole summary, on two lines if need be: it names the report.
                title={
                    <span className="line-clamp-2 whitespace-normal">
                        {report.subject}
                    </span>
                }
                description={
                    <>
                        <span className="font-medium text-foreground tabular-nums">
                            {report.reference}
                        </span>{' '}
                        · sent {formatDate(report.created_at)}
                    </>
                }
                meta={<ReportStatusPill report={report} />}
            />

            <ModalBody className="space-y-6">
                <div className="rounded-xl border border-sidebar-border/70 px-3 pt-4 pb-3 dark:border-sidebar-border">
                    <ReportProgress report={report} />
                </div>

                <ModalSection title="Reply from the SYNAPSE team">
                    {report.reply ? (
                        <figure className="rounded-lg border-l-[3px] border-[#0ABFBF] bg-[#0ABFBF]/6 px-4 py-3">
                            <blockquote className="text-sm leading-relaxed whitespace-pre-wrap">
                                {report.reply}
                            </blockquote>
                            <figcaption className="mt-2 text-xs text-muted-foreground">
                                {formatDateTime(report.replied_at)}
                            </figcaption>
                        </figure>
                    ) : (
                        <p className="rounded-lg border border-dashed border-sidebar-border px-3 py-2.5 text-sm text-muted-foreground">
                            {report.is_open
                                ? 'No reply yet. You’ll get a notification when there is one.'
                                : 'The team closed this without a reply.'}
                        </p>
                    )}
                </ModalSection>

                <ModalSection title="What you wrote">
                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                        {report.description}
                    </p>
                </ModalSection>

                {report.attachments.length > 0 && (
                    <ModalSection title="Screenshots">
                        <ul className="flex flex-wrap gap-2">
                            {report.attachments.map((attachment) => (
                                <li key={attachment.id}>
                                    <a
                                        href={attachment.url}
                                        target="_blank"
                                        rel="noopener"
                                        className="block size-24 overflow-hidden rounded-lg border border-sidebar-border/70 bg-muted transition-opacity hover:opacity-90 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:border-sidebar-border"
                                        aria-label={`Open ${attachment.name}`}
                                    >
                                        <img
                                            src={attachment.url}
                                            alt=""
                                            loading="lazy"
                                            className="size-full object-cover object-top"
                                        />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </ModalSection>
                )}

                <ModalSection title="Details">
                    <dl className="grid gap-px overflow-hidden rounded-lg border border-sidebar-border/70 bg-sidebar-border/70 sm:grid-cols-2 dark:border-sidebar-border dark:bg-sidebar-border">
                        {facts.map(([label, value]) => (
                            <div
                                key={label}
                                className="min-w-0 bg-card px-3 py-2"
                            >
                                <dt className="text-[11px] font-medium text-muted-foreground">
                                    {label}
                                </dt>
                                <dd
                                    className="mt-0.5 truncate text-sm font-medium"
                                    title={value}
                                >
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </ModalSection>
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            </ModalFooter>
        </>
    );
}

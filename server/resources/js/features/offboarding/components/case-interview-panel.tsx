import { router } from '@inertiajs/react';
import { BellRing, Hourglass, MessageSquareText, Send } from 'lucide-react';
import { useState } from 'react';
import { DataTable, TableCard } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { formatDay } from '../constants';
import { offboardingRoutes } from '../routes';
import type { CaseInterview, InterviewQuestion } from '../types';

/**
 * An exit's interview, on its page (ADR 0076): none sent (send one), waiting
 * for answers (and whether a reminder went), waived — or what the leaver said:
 * the reason, the seven ratings as a table, and their own words.
 */
export function CaseInterviewPanel({
    caseHashid,
    interview,
    scale,
    canManage,
    caseCancelled,
}: {
    caseHashid: string;
    interview: CaseInterview | null;
    scale: Record<string, string>;
    canManage: boolean;
    caseCancelled: boolean;
}) {
    const [processing, setProcessing] = useState(false);
    const busy = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    if (interview === null) {
        return (
            <Notice
                icon={<MessageSquareText className="size-5" />}
                title="No exit interview was sent"
                body={
                    caseCancelled
                        ? 'This exit was cancelled.'
                        : 'Surveys go out with every exit except a termination. Send one if you want this leaver’s view.'
                }
                action={
                    canManage &&
                    !caseCancelled && (
                        <Button
                            size="sm"
                            disabled={processing}
                            onClick={() =>
                                router.post(
                                    offboardingRoutes.sendInterview(caseHashid),
                                    {},
                                    busy,
                                )
                            }
                        >
                            <Send className="size-4" />
                            Send exit interview
                        </Button>
                    )
                }
            />
        );
    }

    if (interview.status === 'pending') {
        return (
            <Notice
                icon={<Hourglass className="size-5" />}
                title="Waiting for their answers"
                body={
                    <>
                        Sent {formatDay(interview.sent_on)}.
                        {interview.reminded_on ? (
                            <span className="inline-flex items-center gap-1">
                                {' '}
                                <BellRing className="size-3.5" />
                                Reminded {formatDay(interview.reminded_on)}.
                            </span>
                        ) : (
                            ' They are reminded once if it waits three days.'
                        )}
                    </>
                }
                action={
                    canManage && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={processing}
                            onClick={() =>
                                router.patch(
                                    offboardingRoutes.waiveInterview(
                                        caseHashid,
                                    ),
                                    {},
                                    busy,
                                )
                            }
                        >
                            Stop waiting
                        </Button>
                    )
                }
            />
        );
    }

    if (interview.status === 'waived') {
        return (
            <Notice
                icon={<MessageSquareText className="size-5" />}
                title="Not needed"
                body={`Waived${interview.waived_by ? ` by ${interview.waived_by}` : ''}. The survey was sent ${formatDay(interview.sent_on)}.`}
            />
        );
    }

    const answers = interview.answers ?? {};
    const byType = (type: InterviewQuestion['type']) =>
        interview.questions.filter((question) => question.type === type);
    const label = (question: InterviewQuestion) => {
        const value = answers[question.key];

        return value === undefined || value === ''
            ? '—'
            : (question.options?.[String(value)] ?? String(value));
    };

    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
                Answered {formatDay(interview.submitted_on)}
                {interview.average !== null &&
                    ` · average rating ${interview.average.toFixed(1)} of 5`}
                .
            </p>

            <div className="grid gap-4 lg:grid-cols-2">
                <TableCard title="Their answers">
                    <DataTable>
                        <TableBody>
                            {[...byType('choice'), ...byType('yes_no')].map(
                                (question) => (
                                    <TableRow key={question.key}>
                                        <TableCell className="w-1/2 text-sm whitespace-normal text-muted-foreground">
                                            {question.label}
                                        </TableCell>
                                        <TableCell className="text-sm font-medium whitespace-normal">
                                            {label(question)}
                                        </TableCell>
                                    </TableRow>
                                ),
                            )}
                        </TableBody>
                    </DataTable>
                </TableCard>

                <TableCard title="How they rated us">
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Topic</TableHead>
                                <TableHead>Score</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {byType('rating').map((question) => {
                                const score = Number(answers[question.key]);

                                return (
                                    <TableRow key={question.key}>
                                        <TableCell className="text-sm">
                                            {question.label}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className="inline-flex items-center gap-1"
                                                aria-label={`${score} of 5 — ${scale[score] ?? ''}`}
                                            >
                                                {[1, 2, 3, 4, 5].map((dot) => (
                                                    <span
                                                        key={dot}
                                                        aria-hidden
                                                        className={cn(
                                                            'size-2 rounded-full',
                                                            dot <= score
                                                                ? 'bg-[#08959b] dark:bg-[#0aa7ad]'
                                                                : 'bg-muted',
                                                        )}
                                                    />
                                                ))}
                                                <span className="ml-1.5 text-xs text-muted-foreground">
                                                    {scale[score] ?? '—'}
                                                </span>
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </DataTable>
                </TableCard>
            </div>

            <div className="grid gap-3 lg:grid-cols-3">
                {byType('text').map((question) => (
                    <div
                        key={question.key}
                        className="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                    >
                        <p className="text-xs font-medium text-muted-foreground">
                            {question.label}
                        </p>
                        <p className="mt-1.5 text-sm whitespace-pre-line">
                            {answers[question.key]
                                ? String(answers[question.key])
                                : 'No answer.'}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

function Notice({
    icon,
    title,
    body,
    action,
}: {
    icon: React.ReactNode;
    title: string;
    body: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border">
            <div className="flex items-start gap-3">
                <span className="mt-0.5 text-muted-foreground">{icon}</span>
                <div>
                    <p className="text-sm font-medium">{title}</p>
                    <p className="text-sm text-muted-foreground">{body}</p>
                </div>
            </div>
            {action}
        </div>
    );
}

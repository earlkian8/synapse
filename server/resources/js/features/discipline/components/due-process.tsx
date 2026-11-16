import { Check, Minus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import { calendarDate, dateTime, daysBetween } from '../constants';
import type { CaseDetail } from '../types';

type StepState = 'done' | 'current' | 'waiting' | 'skipped' | 'late';

type Step = {
    key: string;
    title: string;
    detail: ReactNode;
    state: StepState;
};

/**
 * A case's way through the twin-notice rule (ADR 0105, D.O. 147-15), step by
 * step, each with its date: the report, the notice to explain and whether it
 * was received, the written answer and its due date, a hearing when one is
 * asked for or set, and the notice of decision. The same steps for HR and for
 * the employee — the employee is told what is theirs to do.
 */
export function DueProcess({
    case: c,
    today,
    viewer,
}: {
    case: CaseDetail;
    today: string;
    viewer: 'hr' | 'employee';
}) {
    const timeZone = useOrganizationTimeZone();
    const served = c.notice_issued_at !== null;
    const decided = c.decided_at !== null;
    const withdrawn = c.status === 'withdrawn';
    const left = c.reply_due_on ? daysBetween(today, c.reply_due_on) : null;

    const steps: Step[] = [
        {
            key: 'report',
            title: 'Incident reported',
            state: 'done',
            detail: `${calendarDate(c.incident_date)}${c.location ? `, ${c.location}` : ''}`,
        },
        {
            key: 'notice',
            title: 'Notice to explain served',
            state: served ? 'done' : withdrawn ? 'skipped' : 'current',
            detail: served ? (
                <>
                    {dateTime(c.notice_issued_at, timeZone)}
                    <br />
                    {c.notice_received_at
                        ? `Received ${dateTime(c.notice_received_at, timeZone)}`
                        : viewer === 'employee'
                          ? 'Let HR know you’ve received it'
                          : 'Not acknowledged yet'}
                </>
            ) : withdrawn ? (
                'Not served'
            ) : (
                'HR reads the report and decides whether to charge'
            ),
        },
        {
            key: 'answer',
            title: 'Written explanation',
            state: c.explained_at
                ? 'done'
                : !served || withdrawn
                  ? 'waiting'
                  : c.reply_overdue
                    ? 'late'
                    : decided
                      ? 'skipped'
                      : 'current',
            detail: c.explained_at
                ? `Answered ${dateTime(c.explained_at, timeZone)}${c.explanation_by_hr ? ', handed in on paper' : ''}`
                : !served
                  ? `At least five calendar days to answer`
                  : c.reply_overdue
                    ? `No answer by ${calendarDate(c.reply_due_on)}: the right to answer was waived`
                    : decided
                      ? 'Not answered'
                      : `Due ${calendarDate(c.reply_due_on)}${left !== null ? ` (${left === 0 ? 'today' : left === 1 ? '1 day left' : `${left} days left`})` : ''}`,
        },
        ...(c.hearing_requested || c.hearing_at
            ? [
                  {
                      key: 'hearing',
                      title: 'Hearing',
                      state: (c.hearing_held_at
                          ? 'done'
                          : c.hearing_at
                            ? 'current'
                            : decided || withdrawn
                              ? 'skipped'
                              : 'current') as StepState,
                      detail: c.hearing_held_at
                          ? `Held ${calendarDate(c.hearing_at)}${c.hearing_attended === false ? ', not attended' : ''}`
                          : c.hearing_at
                            ? `${dateTime(c.hearing_at, timeZone)}${c.hearing_place ? `, ${c.hearing_place}` : ''}`
                            : 'Asked for in writing; to be set',
                  },
              ]
            : []),
        {
            key: 'decision',
            title: withdrawn ? 'Withdrawn' : 'Notice of decision',
            state: decided ? 'done' : withdrawn ? 'skipped' : 'waiting',
            detail: decided ? (
                <>
                    {c.finding === 'upheld'
                        ? `Upheld: ${c.penalty_label?.toLowerCase()}`
                        : 'Not upheld'}
                    , {dateTime(c.decided_at, timeZone)}
                    <br />
                    {c.decision_received_at
                        ? `Received ${dateTime(c.decision_received_at, timeZone)}`
                        : viewer === 'employee'
                          ? 'Let HR know you’ve received it'
                          : 'Not acknowledged yet'}
                </>
            ) : withdrawn ? (
                c.withdrawn_reason
            ) : (
                'Every circumstance weighed, and the reasons given'
            ),
        },
    ];

    return (
        <ol aria-label="Due process" className="relative space-y-0">
            {steps.map((step, index) => (
                <li
                    key={step.key}
                    className="relative flex gap-3 pb-5 last:pb-0"
                >
                    {index < steps.length - 1 && (
                        <span
                            aria-hidden
                            className={cn(
                                'absolute top-7 bottom-0 left-[13px] w-px',
                                step.state === 'done'
                                    ? 'bg-[#0ABFBF]'
                                    : 'bg-border',
                            )}
                        />
                    )}
                    <span
                        className={cn(
                            'relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold',
                            step.state === 'done' &&
                                'border-transparent bg-[#0ABFBF] text-white',
                            step.state === 'current' &&
                                'border-[#0ABFBF] bg-card text-[#08767c] dark:text-[#0ABFBF]',
                            step.state === 'late' &&
                                'border-rose-500 bg-card text-rose-600',
                            (step.state === 'waiting' ||
                                step.state === 'skipped') &&
                                'bg-card text-muted-foreground',
                        )}
                    >
                        {step.state === 'done' ? (
                            <Check className="size-3.5" />
                        ) : step.state === 'skipped' ? (
                            <Minus className="size-3.5" />
                        ) : (
                            index + 1
                        )}
                        <span className="sr-only">
                            {
                                {
                                    done: 'Done',
                                    current: 'Now',
                                    late: 'Overdue',
                                    waiting: 'Later',
                                    skipped: 'Skipped',
                                }[step.state]
                            }
                        </span>
                    </span>
                    <div className="min-w-0 pt-0.5">
                        <p
                            className={cn(
                                'text-sm font-medium',
                                (step.state === 'waiting' ||
                                    step.state === 'skipped') &&
                                    'text-muted-foreground',
                            )}
                        >
                            {step.title}
                        </p>
                        <p
                            className={cn(
                                'text-xs leading-relaxed text-muted-foreground',
                                step.state === 'late' &&
                                    'text-rose-600 dark:text-rose-400',
                            )}
                        >
                            {step.detail}
                        </p>
                    </div>
                </li>
            ))}
        </ol>
    );
}

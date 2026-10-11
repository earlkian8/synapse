import { Link, useForm } from '@inertiajs/react';
import { ArrowUpRight, ClipboardList, TriangleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    formatDay,
    RESIGNATION_STATUS_LABELS,
    RESIGNATION_STATUS_STYLES,
} from '../constants';
import { offboardingRoutes } from '../routes';
import type { ProgramOption, ResignationRow } from '../types';
import { StatusPill } from './status-pill';

const AUTO = '__auto__';

/**
 * One resignation, for HR (ADR 0076): who, when they filed it, the last day
 * they propose and the notice that gives, and why. One still waiting is
 * accepted — the last day agreed, the clearance template to start from — or
 * declined with a note the employee is shown.
 */
export function ResignationReviewDialog({
    resignation,
    open,
    onOpenChange,
    programs,
    noticeDays,
    canManage,
}: {
    resignation: ResignationRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    programs: ProgramOption[];
    noticeDays: number;
    canManage: boolean;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <ClipboardList />
                        </ModalIcon>
                    }
                    title={
                        resignation
                            ? `${resignation.employee.full_name}’s resignation`
                            : 'Resignation'
                    }
                    description={
                        resignation?.status === 'pending'
                            ? 'Accept it to start their exit and clearance, or decline it and say why.'
                            : 'Decided — kept for the record.'
                    }
                />
                {open && resignation && (
                    <Review
                        key={resignation.id}
                        resignation={resignation}
                        programs={programs}
                        noticeDays={noticeDays}
                        canManage={canManage}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Review({
    resignation,
    programs,
    noticeDays,
    canManage,
    onDone,
}: {
    resignation: ResignationRow;
    programs: ProgramOption[];
    noticeDays: number;
    canManage: boolean;
    onDone: () => void;
}) {
    const deciding = canManage && resignation.status === 'pending';
    const shortNotice = resignation.notice_days < noticeDays;

    const { data, setData, patch, processing, errors, transform } = useForm({
        decision: 'accept' as 'accept' | 'decline',
        last_working_day: resignation.proposed_last_day,
        offboarding_program_id: AUTO,
        note: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        transform((current) => ({
            ...current,
            offboarding_program_id:
                current.offboarding_program_id === AUTO
                    ? null
                    : Number(current.offboarding_program_id),
            note: current.note || null,
        }));
        patch(offboardingRoutes.decide(resignation.id), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="flex flex-col gap-5">
                <div className="flex items-center gap-3">
                    <PersonAvatar
                        name={resignation.employee.full_name}
                        initials={resignation.employee.initials}
                        photo={resignation.employee.photo}
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold">
                            {resignation.employee.full_name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {[
                                resignation.employee.position,
                                resignation.employee.department,
                                resignation.employee.employee_no,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    </div>
                    <StatusPill
                        label={RESIGNATION_STATUS_LABELS[resignation.status]}
                        className={
                            RESIGNATION_STATUS_STYLES[resignation.status]
                        }
                    />
                </div>

                <dl className="grid grid-cols-3 gap-3 rounded-lg border border-border p-3 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">Filed</dt>
                        <dd className="tabular-nums">
                            {formatDay(resignation.filed_on)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Last day proposed
                        </dt>
                        <dd className="tabular-nums">
                            {formatDay(resignation.proposed_last_day)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Notice
                        </dt>
                        <dd
                            className={cn(
                                'tabular-nums',
                                shortNotice &&
                                    'font-medium text-amber-600 dark:text-amber-400',
                            )}
                        >
                            {resignation.notice_days} days
                        </dd>
                    </div>
                </dl>

                {shortNotice && resignation.status === 'pending' && (
                    <p className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        <TriangleAlert className="mt-px size-3.5 shrink-0" />
                        Less than the {noticeDays} days’ notice the Labor Code
                        asks for. Agree an earlier day if you can spare them, or
                        set a later one.
                    </p>
                )}

                <div>
                    <p className="mb-1 text-xs text-muted-foreground">
                        Why they are leaving
                    </p>
                    <p className="text-sm whitespace-pre-line">
                        {resignation.reason ?? 'They didn’t say.'}
                    </p>
                </div>

                {!deciding && resignation.decided_on && (
                    <div className="rounded-lg bg-muted/50 p-3 text-sm">
                        <p>
                            {RESIGNATION_STATUS_LABELS[resignation.status]}{' '}
                            {formatDay(resignation.decided_on)}
                            {resignation.decided_by &&
                                ` by ${resignation.decided_by}`}
                            .
                        </p>
                        {resignation.decision_note && (
                            <p className="mt-1 text-muted-foreground">
                                {resignation.decision_note}
                            </p>
                        )}
                    </div>
                )}

                {deciding && (
                    <div className="flex flex-col gap-4 border-t border-border pt-4">
                        <div
                            role="radiogroup"
                            aria-label="Decision"
                            className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1"
                        >
                            {(['accept', 'decline'] as const).map((choice) => (
                                <button
                                    key={choice}
                                    type="button"
                                    role="radio"
                                    aria-checked={data.decision === choice}
                                    onClick={() => setData('decision', choice)}
                                    className={cn(
                                        'rounded-md px-2 py-1.5 text-sm font-medium transition-colors',
                                        data.decision === choice
                                            ? 'bg-background text-foreground shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {choice === 'accept' ? 'Accept' : 'Decline'}
                                </button>
                            ))}
                        </div>

                        {data.decision === 'accept' ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    label="Last working day"
                                    required
                                    error={errors.last_working_day}
                                    hint="As agreed with them."
                                >
                                    <Input
                                        type="date"
                                        value={data.last_working_day}
                                        onChange={(event) =>
                                            setData(
                                                'last_working_day',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                                <FormField
                                    label="Clearance checklist"
                                    error={errors.offboarding_program_id}
                                    hint="Best match picks by department and exit type."
                                >
                                    <FormSelect
                                        value={data.offboarding_program_id}
                                        onChange={(value) =>
                                            setData(
                                                'offboarding_program_id',
                                                value,
                                            )
                                        }
                                        options={[
                                            {
                                                value: AUTO,
                                                label: 'Best match',
                                            },
                                            ...programs.map((program) => ({
                                                value: String(program.id),
                                                label: `${program.name} (${program.items_count})`,
                                            })),
                                        ]}
                                    />
                                </FormField>
                                <FormField
                                    label="Note to them"
                                    error={errors.note}
                                    className="sm:col-span-2"
                                    hint="Optional."
                                >
                                    <Input
                                        value={data.note}
                                        maxLength={1000}
                                        onChange={(event) =>
                                            setData('note', event.target.value)
                                        }
                                    />
                                </FormField>
                            </div>
                        ) : (
                            <FormField
                                label="Why you are declining"
                                required
                                error={errors.note}
                                hint="They see this, so say what they can do next."
                            >
                                <textarea
                                    value={data.note}
                                    onChange={(event) =>
                                        setData('note', event.target.value)
                                    }
                                    rows={3}
                                    maxLength={1000}
                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                />
                            </FormField>
                        )}
                    </div>
                )}
            </ModalBody>

            <ModalFooter>
                {resignation.case_id && (
                    <Button variant="outline" asChild className="mr-auto">
                        <Link
                            href={offboardingRoutes.show(resignation.case_id)}
                        >
                            Open their exit
                            <ArrowUpRight className="size-4" />
                        </Link>
                    </Button>
                )}
                <Button type="button" variant="ghost" onClick={onDone}>
                    {deciding ? 'Cancel' : 'Close'}
                </Button>
                {deciding && (
                    <Button
                        type="submit"
                        variant={
                            data.decision === 'decline'
                                ? 'destructive'
                                : 'default'
                        }
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        {data.decision === 'accept'
                            ? 'Accept and start exit'
                            : 'Decline resignation'}
                    </Button>
                )}
            </ModalFooter>
        </form>
    );
}

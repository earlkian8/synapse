import { useForm } from '@inertiajs/react';
import {
    CalendarClock,
    FileSignature,
    Gavel,
    MessageSquareText,
    PauseCircle,
    Undo2,
    Users,
} from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
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
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { TEXTAREA } from '@/features/training/constants';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import { calendarDate, penaltyStep, PENALTY_LABELS } from '../constants';
import { disciplineRoutes } from '../routes';
import type { CasePageProps, HrCase, Offense, Penalty } from '../types';

type Base = {
    case: HrCase;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** The shell every step's dialog shares: what it does, the form, and one action. */
function ActionModal({
    open,
    onOpenChange,
    icon,
    title,
    description,
    submitLabel,
    processing,
    onSubmit,
    destructive = false,
    size = 'md',
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    icon: ReactNode;
    title: string;
    description: ReactNode;
    submitLabel: string;
    processing: boolean;
    onSubmit: () => void;
    destructive?: boolean;
    size?: 'sm' | 'md' | 'lg';
    children: ReactNode;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size={size}>
                <ModalHeader
                    icon={<ModalIcon>{icon}</ModalIcon>}
                    title={title}
                    description={description}
                />
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit();
                    }}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <ModalBody className="space-y-5">{children}</ModalBody>
                    <ModalFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </ModalFooter>
                </form>
            </ModalContent>
        </Modal>
    );
}

/**
 * The notice to explain (D.O. 147-15): the specific charge — which rule, and
 * how it was broken — and the day an answer is due, five calendar days away at
 * the soonest. The report's narrative goes on the notice as the facts.
 */
export function NoticeDialog({
    case: c,
    open,
    onOpenChange,
    offenses,
    earliestReply,
}: Base & { offenses: Offense[]; earliestReply: string }) {
    const { data, setData, post, transform, processing, errors } = useForm({
        conduct_offense_id: c.offense ? String(c.offense.id) : '',
        notice_grounds: c.offense
            ? `Violation of ${c.offense.code} ${c.offense.title}: `
            : '',
        reply_due_on: earliestReply,
    });

    const chosen = offenses.find(
        (offense) => String(offense.id) === data.conduct_offense_id,
    );

    return (
        <ActionModal
            open={open}
            onOpenChange={onOpenChange}
            icon={<FileSignature />}
            title="Serve a notice to explain"
            description="The first written notice. The employee is told at once and can answer in the app."
            submitLabel="Serve the notice"
            processing={processing}
            size="lg"
            onSubmit={() => {
                transform((payload) => ({
                    ...payload,
                    conduct_offense_id:
                        payload.conduct_offense_id === ''
                            ? null
                            : Number(payload.conduct_offense_id),
                }));
                post(disciplineRoutes.notice(c.hashid), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                });
            }}
        >
            <FormField
                label="Offense charged"
                error={errors.conduct_offense_id}
                hint={
                    chosen ? chosen.ground_label : 'From your code of conduct.'
                }
            >
                <FormSelect
                    value={data.conduct_offense_id}
                    onChange={(value) => {
                        const offense = offenses.find(
                            (o) => String(o.id) === value,
                        );
                        setData((current) => ({
                            ...current,
                            conduct_offense_id: value,
                            notice_grounds:
                                current.notice_grounds.trim() === '' ||
                                current.notice_grounds.startsWith(
                                    'Violation of ',
                                )
                                    ? `Violation of ${offense?.code} ${offense?.title}: `
                                    : current.notice_grounds,
                        }));
                    }}
                    placeholder="Choose the offense"
                    options={offenses.map((offense) => ({
                        value: String(offense.id),
                        label: `${offense.code} ${offense.title}`,
                    }))}
                />
            </FormField>

            <FormField
                label="The charge"
                required
                error={errors.notice_grounds}
                hint="Name the rule and say how it was broken. A general description won’t do."
            >
                <textarea
                    value={data.notice_grounds}
                    onChange={(event) =>
                        setData('notice_grounds', event.target.value)
                    }
                    rows={4}
                    maxLength={3000}
                    className={TEXTAREA}
                />
            </FormField>

            <div className="rounded-xl border bg-muted/30 p-3 text-sm">
                <p className="mb-1 text-xs font-medium text-muted-foreground">
                    The facts, as the notice will state them
                </p>
                <p className="line-clamp-4 whitespace-pre-line">
                    {c.narrative}
                </p>
            </div>

            <FormField
                label="Answer due"
                required
                error={errors.reply_due_on}
                hint={`At least five calendar days: ${calendarDate(earliestReply)} or later.`}
            >
                <Input
                    type="date"
                    value={data.reply_due_on}
                    min={earliestReply}
                    onChange={(event) =>
                        setData('reply_due_on', event.target.value)
                    }
                    className="sm:w-56"
                />
            </FormField>
        </ActionModal>
    );
}

/** An explanation handed in on paper, typed in by HR. */
export function ExplanationDialog({ case: c, open, onOpenChange }: Base) {
    const { data, setData, post, processing, errors } = useForm({
        explanation: c.explanation ?? '',
        hearing: c.hearing_requested,
    });
    const id = useId();

    return (
        <ActionModal
            open={open}
            onOpenChange={onOpenChange}
            icon={<MessageSquareText />}
            title="Record a written explanation"
            description="For an answer handed in on paper. Type it as written; keep the signed copy."
            submitLabel="Record explanation"
            processing={processing}
            size="lg"
            onSubmit={() =>
                post(disciplineRoutes.explanation(c.hashid), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
        >
            <FormField
                label="Their explanation"
                required
                error={errors.explanation}
            >
                <textarea
                    value={data.explanation}
                    onChange={(e) => setData('explanation', e.target.value)}
                    rows={8}
                    maxLength={10000}
                    className={TEXTAREA}
                />
            </FormField>
            <label htmlFor={id} className="flex items-start gap-2.5 text-sm">
                <Checkbox
                    id={id}
                    checked={data.hearing}
                    onCheckedChange={(value) =>
                        setData('hearing', value === true)
                    }
                />
                <span>They asked in writing for a hearing</span>
            </label>
        </ActionModal>
    );
}

/** When and where a hearing or conference is held. */
export function HearingDialog({
    case: c,
    open,
    onOpenChange,
    today,
}: Base & { today: string }) {
    const timeZone = useOrganizationTimeZone();
    const { data, setData, post, processing, errors } = useForm({
        hearing_at: c.hearing_at
            ? localInput(c.hearing_at, timeZone)
            : `${today}T10:00`,
        hearing_place: c.hearing_place ?? '',
    });

    return (
        <ActionModal
            open={open}
            onOpenChange={onOpenChange}
            icon={<CalendarClock />}
            title={c.hearing_at ? 'Move the hearing' : 'Set a hearing'}
            description="The employee can explain in person, present evidence and bring a representative. They are told when and where."
            submitLabel={c.hearing_at ? 'Move hearing' : 'Set hearing'}
            processing={processing}
            onSubmit={() =>
                post(disciplineRoutes.hearing(c.hashid), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
        >
            <div className="grid gap-5 sm:grid-cols-2">
                <FormField label="When" required error={errors.hearing_at}>
                    <Input
                        type="datetime-local"
                        value={data.hearing_at}
                        min={`${today}T00:00`}
                        onChange={(e) => setData('hearing_at', e.target.value)}
                    />
                </FormField>
                <FormField label="Where" error={errors.hearing_place}>
                    <Input
                        value={data.hearing_place}
                        onChange={(e) =>
                            setData('hearing_place', e.target.value)
                        }
                        maxLength={160}
                        placeholder="HR office, 2nd floor"
                    />
                </FormField>
            </div>
        </ActionModal>
    );
}

/** What was said at the hearing, and whether they came. */
export function RecordHearingDialog({ case: c, open, onOpenChange }: Base) {
    const { data, setData, post, processing, errors } = useForm({
        attended: c.hearing_attended ?? true,
        hearing_notes: c.hearing_notes ?? '',
    });

    return (
        <ActionModal
            open={open}
            onOpenChange={onOpenChange}
            icon={<Users />}
            title="Record the hearing"
            description="The minutes: who was there, what was said and shown. A hearing they didn’t come to still counts as their chance to be heard."
            submitLabel="Record hearing"
            processing={processing}
            size="lg"
            onSubmit={() =>
                post(disciplineRoutes.recordHearing(c.hashid), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
        >
            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">Did they come?</legend>
                <div className="inline-flex rounded-lg border bg-card p-0.5">
                    {[
                        { value: true, label: 'They came' },
                        { value: false, label: 'They didn’t come' },
                    ].map((option) => (
                        <button
                            key={String(option.value)}
                            type="button"
                            aria-pressed={data.attended === option.value}
                            onClick={() => setData('attended', option.value)}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                data.attended === option.value
                                    ? 'bg-[#0ABFBF]/10 text-[#08767c] dark:text-[#0ABFBF]'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            </fieldset>
            <FormField label="Minutes" required error={errors.hearing_notes}>
                <textarea
                    value={data.hearing_notes}
                    onChange={(e) => setData('hearing_notes', e.target.value)}
                    rows={8}
                    maxLength={10000}
                    className={TEXTAREA}
                />
            </FormField>
        </ActionModal>
    );
}

/** A preventive suspension while the case is heard: at most 30 days, not a penalty. */
export function PreventiveDialog({
    case: c,
    open,
    onOpenChange,
    today,
    maxDays,
}: Base & { today: string; maxDays: number }) {
    const { data, setData, post, processing, errors } = useForm({
        preventive_from: c.preventive_from ?? today,
        days: '7',
    });

    return (
        <ActionModal
            open={open}
            onOpenChange={onOpenChange}
            icon={<PauseCircle />}
            title="Preventive suspension"
            description="Only when their staying at work is a serious and imminent threat to life or property, or to the evidence. It is not a penalty."
            submitLabel="Suspend while heard"
            processing={processing}
            onSubmit={() =>
                post(disciplineRoutes.preventive(c.hashid), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
        >
            <div className="grid gap-5 sm:grid-cols-2">
                <FormField label="From" required error={errors.preventive_from}>
                    <Input
                        type="date"
                        value={data.preventive_from}
                        onChange={(e) =>
                            setData('preventive_from', e.target.value)
                        }
                    />
                </FormField>
                <FormField
                    label="Days"
                    required
                    error={errors.days}
                    hint={`At most ${maxDays}. Past that, take them back or pay them.`}
                >
                    <Input
                        type="number"
                        min={1}
                        max={maxDays}
                        value={data.days}
                        onChange={(e) => setData('days', e.target.value)}
                    />
                </FormField>
            </div>
        </ActionModal>
    );
}

/**
 * The notice of decision: upheld or not, the penalty — the code of conduct's
 * step for how many times it has happened, offered first — and why, every
 * circumstance weighed.
 */
export function DecisionDialog({
    case: c,
    open,
    onOpenChange,
    suggestion,
    today,
    maxSuspension,
}: Base & {
    suggestion: CasePageProps['suggestion'];
    today: string;
    maxSuspension: number;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        finding: 'upheld' as 'upheld' | 'cleared',
        penalty: (suggestion.penalty ?? 'written_warning') as Penalty,
        suspension_days: String(suggestion.days ?? 3),
        penalty_from: today,
        decision:
            'All the circumstances of the charge, the employee’s explanation and the evidence were considered. ',
    });

    const upheld = data.finding === 'upheld';
    const noJustCause =
        upheld && data.penalty === 'dismissal' && !c.offense?.just_cause;
    const ordinal = (n: number) =>
        `${n}${n % 10 === 1 && n % 100 !== 11 ? 'st' : n % 10 === 2 && n % 100 !== 12 ? 'nd' : n % 10 === 3 && n % 100 !== 13 ? 'rd' : 'th'}`;

    return (
        <ActionModal
            open={open}
            onOpenChange={onOpenChange}
            icon={<Gavel />}
            title="Decide the case"
            description="The second written notice. The employee is told at once and reads it in the app."
            submitLabel="Serve the decision"
            processing={processing}
            size="lg"
            onSubmit={() => {
                transform((payload) => ({
                    finding: payload.finding,
                    penalty:
                        payload.finding === 'upheld' ? payload.penalty : null,
                    suspension_days:
                        payload.finding === 'upheld' &&
                        payload.penalty === 'suspension'
                            ? Number(payload.suspension_days)
                            : null,
                    penalty_from:
                        payload.finding === 'upheld' &&
                        ['suspension', 'dismissal'].includes(payload.penalty)
                            ? payload.penalty_from
                            : null,
                    decision: payload.decision,
                }));
                post(disciplineRoutes.decide(c.hashid), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                });
            }}
        >
            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">Finding</legend>
                <div className="grid gap-2 sm:grid-cols-2">
                    {[
                        {
                            value: 'upheld',
                            label: 'The charge is upheld',
                            hint: 'The evidence shows the offense was committed.',
                        },
                        {
                            value: 'cleared',
                            label: 'Not upheld',
                            hint: 'No penalty. It stays on file as cleared.',
                        },
                    ].map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={data.finding === option.value}
                            onClick={() =>
                                setData(
                                    'finding',
                                    option.value as 'upheld' | 'cleared',
                                )
                            }
                            className={cn(
                                'rounded-xl border p-3 text-left transition-colors',
                                data.finding === option.value
                                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/5'
                                    : 'hover:bg-muted/50',
                            )}
                        >
                            <span className="block text-sm font-medium">
                                {option.label}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {option.hint}
                            </span>
                        </button>
                    ))}
                </div>
            </fieldset>

            {upheld && (
                <>
                    {c.offense && (
                        <p className="rounded-xl bg-muted/50 px-3 py-2.5 text-sm">
                            The {ordinal(suggestion.occurrence)} upheld{' '}
                            {c.offense.code} for this employee
                            {suggestion.prior.length > 0
                                ? ` (after ${suggestion.prior.join(', ')})`
                                : ''}
                            .{' '}
                            {suggestion.penalty
                                ? `The code of conduct gives ${penaltyStep({ action: suggestion.penalty, days: suggestion.days }).toLowerCase()}.`
                                : ''}
                        </p>
                    )}

                    <div className="grid gap-5 sm:grid-cols-[1fr_8rem_11rem]">
                        <FormField
                            label="Penalty"
                            required
                            error={errors.penalty}
                        >
                            <FormSelect
                                value={data.penalty}
                                onChange={(value) =>
                                    setData('penalty', value as Penalty)
                                }
                                options={Object.entries(PENALTY_LABELS).map(
                                    ([value, label]) => ({
                                        value,
                                        label:
                                            value === suggestion.penalty
                                                ? `${label} (the code’s step)`
                                                : label,
                                    }),
                                )}
                            />
                        </FormField>
                        {data.penalty === 'suspension' && (
                            <FormField
                                label="Days"
                                required
                                error={errors.suspension_days}
                            >
                                <Input
                                    type="number"
                                    min={1}
                                    max={maxSuspension}
                                    value={data.suspension_days}
                                    onChange={(e) =>
                                        setData(
                                            'suspension_days',
                                            e.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                        {(data.penalty === 'suspension' ||
                            data.penalty === 'dismissal') && (
                            <FormField
                                label={
                                    data.penalty === 'dismissal'
                                        ? 'Effective'
                                        : 'Starting'
                                }
                                error={errors.penalty_from}
                            >
                                <Input
                                    type="date"
                                    value={data.penalty_from}
                                    onChange={(e) =>
                                        setData('penalty_from', e.target.value)
                                    }
                                />
                            </FormField>
                        )}
                    </div>

                    {noJustCause && (
                        <p className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-700 dark:text-rose-300">
                            Dismissal needs a just cause under Article 297.{' '}
                            {c.offense
                                ? 'This offense rests on a company rule only.'
                                : 'Charge the case under an offense first.'}
                        </p>
                    )}
                </>
            )}

            <FormField
                label="Reasons"
                required
                error={errors.decision}
                hint="What the evidence showed, what their explanation said and why it did or didn’t answer the charge."
            >
                <textarea
                    value={data.decision}
                    onChange={(e) => setData('decision', e.target.value)}
                    rows={7}
                    maxLength={10000}
                    className={TEXTAREA}
                />
            </FormField>
        </ActionModal>
    );
}

/** Drop the case before a decision, with why. */
export function WithdrawDialog({ case: c, open, onOpenChange }: Base) {
    const { data, setData, patch, processing, errors } = useForm({
        withdrawn_reason: '',
    });

    return (
        <ActionModal
            open={open}
            onOpenChange={onOpenChange}
            icon={<Undo2 />}
            title={`Withdraw ${c.reference}?`}
            description={
                c.notice_issued_at
                    ? 'The employee is told nothing will come of it. It stays on file as withdrawn.'
                    : 'Nothing was served, so the employee never hears of it.'
            }
            submitLabel="Withdraw case"
            processing={processing}
            destructive
            onSubmit={() =>
                patch(disciplineRoutes.withdraw(c.hashid), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
        >
            <FormField label="Why" required error={errors.withdrawn_reason}>
                <textarea
                    value={data.withdrawn_reason}
                    onChange={(e) =>
                        setData('withdrawn_reason', e.target.value)
                    }
                    rows={3}
                    maxLength={1000}
                    className={TEXTAREA}
                />
            </FormField>
        </ActionModal>
    );
}

/** An instant as a `datetime-local` value on the company's clock: "2026-12-12T10:00". */
function localInput(iso: string, timeZone?: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(iso));
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((p) => p.type === type)?.value ?? '00';

    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

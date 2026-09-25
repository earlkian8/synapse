import { ArrowRight, Check, Minus, UserPlus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { GROUPS, STEPS } from '../constants';
import type { SetupStep, StepStatus } from '../types';

type Props = {
    companyName: string;
    statuses: Record<SetupStep, StepStatus>;
    onRevisit: (step: SetupStep) => void;
    onBack: () => void;
    /** Close setup — to the dashboard, or on to inviting people. */
    onFinish: (next?: 'people') => void;
    finishing: boolean;
    /** Already closed once — the wizard was reopened from Company Setup. */
    alreadyCompleted: boolean;
    /** Whether "bring your people in" can point at inviting them. */
    canInvite: boolean;
};

/**
 * The send-off. It states what was actually configured and what was passed over,
 * with a way back into each — a skipped step is unfinished business, not a
 * failure, and the screen should not pretend it did not happen — and then the
 * one thing setup cannot do: bring the people in.
 */
export default function DoneScreen({
    companyName,
    statuses,
    onRevisit,
    onBack,
    onFinish,
    finishing,
    alreadyCompleted,
    canInvite,
}: Props) {
    const outstanding = STEPS.filter(
        (meta) => statuses[meta.step] !== 'done',
    ).length;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-10 md:px-8 md:py-12">
                <div className="mx-auto flex w-full max-w-3xl flex-col">
                    <span className="flex size-11 items-center justify-center rounded-xl bg-[#0ABFBF]/10 text-[#0ABFBF]">
                        <Check className="size-5" strokeWidth={2.5} />
                    </span>

                    <h1 className="mt-5 text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                        {outstanding === 0
                            ? `${companyName} is fully set up.`
                            : `${companyName} is ready to use.`}
                    </h1>
                    <p className="mt-3 max-w-lg text-sm leading-relaxed text-muted-foreground">
                        {outstanding === 0
                            ? 'Everything the modules read from is in place. Add your people next — the rest of the system fills itself in from there.'
                            : `Here's where each step landed. ${outstanding === 1 ? 'One is' : `${outstanding} are`} still open, and you can pick ${outstanding === 1 ? 'it' : 'them'} up any time from Company Setup → Setup Guide.`}
                    </p>

                    <div className="mt-7 grid gap-x-8 gap-y-6 md:grid-cols-2">
                        {GROUPS.map(({ group, label }) => (
                            <section
                                key={group}
                                className={
                                    group === 'people' ? 'md:col-span-2' : ''
                                }
                            >
                                <h2 className="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                                    {label}
                                </h2>
                                <ul
                                    className={
                                        group === 'people'
                                            ? 'mt-1 grid gap-x-8 md:grid-cols-2'
                                            : 'mt-1 flex flex-col'
                                    }
                                >
                                    {STEPS.filter(
                                        (meta) => meta.group === group,
                                    ).map((meta) => (
                                        <StepLine
                                            key={meta.step}
                                            label={meta.label}
                                            status={statuses[meta.step]}
                                            onRevisit={() =>
                                                onRevisit(meta.step)
                                            }
                                        />
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>

                    {canInvite && (
                        <button
                            type="button"
                            onClick={() => onFinish('people')}
                            disabled={finishing}
                            className="group mt-9 flex items-center gap-4 rounded-xl border border-sidebar-border/70 bg-card p-4 text-left transition-colors hover:border-[#0ABFBF]/50 focus-visible:ring-2 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none disabled:opacity-60 dark:border-sidebar-border"
                        >
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <UserPlus className="size-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-semibold text-foreground">
                                    {alreadyCompleted
                                        ? 'Bring your people in'
                                        : 'Finish, and bring your people in'}
                                </span>
                                <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                                    Invite them by email or share the company
                                    join code. Everything set up here applies to
                                    them from their first day.
                                </span>
                            </span>
                            <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                        </button>
                    )}
                </div>
            </div>

            <div className="shrink-0 border-t border-sidebar-border/70 bg-background/85 px-5 py-3.5 backdrop-blur md:px-8 dark:border-sidebar-border">
                <div className="mx-auto flex w-full max-w-3xl items-center">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onBack}
                        disabled={finishing}
                        className="text-muted-foreground"
                    >
                        Back
                    </Button>
                    <Button
                        type="button"
                        onClick={() => onFinish()}
                        disabled={finishing}
                        className="ml-auto"
                    >
                        {finishing && <Spinner />}
                        {alreadyCompleted
                            ? 'Back to dashboard'
                            : 'Finish and go to dashboard'}
                        {!finishing && <ArrowRight className="size-4" />}
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * One step's outcome, and the way back into it — the step itself, which carries
 * everything its Company Setup screen does, so a finished step is reviewed where
 * it was done.
 */
function StepLine({
    label,
    status,
    onRevisit,
}: {
    label: string;
    status: StepStatus;
    onRevisit: () => void;
}) {
    return (
        <li className="flex items-center gap-3 border-b border-sidebar-border/70 py-2.5 dark:border-sidebar-border">
            <span
                className={
                    status === 'done'
                        ? 'flex size-5 shrink-0 items-center justify-center rounded-full bg-[#0ABFBF] text-white'
                        : 'flex size-5 shrink-0 items-center justify-center rounded-full border border-input text-muted-foreground'
                }
            >
                {status === 'done' ? (
                    <Check className="size-3" strokeWidth={3} />
                ) : (
                    <Minus className="size-3" strokeWidth={3} />
                )}
            </span>

            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium text-foreground">
                    {label}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {status === 'done'
                        ? 'Configured'
                        : status === 'skipped'
                          ? 'Skipped for now'
                          : 'Not started'}
                </span>
            </span>

            <button
                type="button"
                onClick={onRevisit}
                className={
                    status === 'done'
                        ? 'shrink-0 text-xs font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline'
                        : 'shrink-0 text-xs font-medium text-[#0a8b91] underline-offset-4 hover:underline dark:text-[#0ABFBF]'
                }
            >
                {status === 'done' ? 'Review' : 'Do it now'}
            </button>
        </li>
    );
}

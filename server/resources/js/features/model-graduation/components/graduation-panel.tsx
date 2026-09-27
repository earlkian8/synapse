import {
    BookOpen,
    ChevronDown,
    GraduationCap,
    Scale,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ConfirmDialog } from '@/features/roles/components/confirm-dialog';
import { cn } from '@/lib/utils';
import { MODEL_COPY } from '../constants';
import type { Graduation, Requirement } from '../types';
import { useGraduation } from '../use-graduation';
import { FieldCoverageTable } from './field-coverage';
import { RequirementChecklist } from './requirement-checklist';
import { RequirementDialog } from './requirement-dialog';
import { StageRail } from './stage-rail';
import { TrainingPanel } from './training-panel';

/**
 * The model-graduation panel each predictive surface embeds beneath its header
 * (ADR 0046). It answers, in this order, what a reader needs:
 *
 *  1. *Whose data is behind these scores?* — the one-line headline, always visible;
 *  2. *What is "graduation"?* — four short points;
 *  3. *Where are we?* — the three steps, with "you are here";
 *  4. *What is still needed, and what can I do about it?* — the checklist;
 *  5. *What happens when it's ready?* — train, check, switch (and switch back).
 *
 * Every figure is counted from the organisation's own records on the server.
 */
export function GraduationPanel({
    graduation,
    canManage,
}: {
    graduation: Graduation;
    canManage: boolean;
}) {
    // Open straight away when there is a decision waiting; otherwise the headline
    // is the whole message until the reader asks for more.
    const [open, setOpen] = useState(
        graduation.gate_open || graduation.latest?.status === 'ready',
    );
    const [detail, setDetail] = useState<Requirement | null>(null);
    const actions = useGraduation(graduation.model);
    const copy = MODEL_COPY[graduation.model];

    return (
        <>
            <Collapsible
                open={open}
                onOpenChange={setOpen}
                className="rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
            >
                <CollapsibleTrigger className="flex w-full items-start gap-3 rounded-xl px-4 py-4 text-left transition-colors hover:bg-muted/40">
                    <span
                        className={cn(
                            'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg',
                            graduation.stage === 'graduated'
                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                : 'bg-[#0ABFBF]/10 text-teal-700 dark:text-[#0ABFBF]',
                        )}
                    >
                        <GraduationCap className="size-4.5" />
                    </span>

                    <div className="min-w-0 flex-1">
                        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium">
                            Model graduation
                            <StagePill graduation={graduation} />
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            <Headline graduation={graduation} />
                        </p>
                        <RequirementDots graduation={graduation} />
                    </div>

                    <span className="flex shrink-0 items-center gap-1.5 pt-0.5 text-xs font-medium text-muted-foreground">
                        <span className="hidden sm:inline">
                            {open ? 'Hide details' : 'See what’s needed'}
                        </span>
                        <ChevronDown
                            className={cn(
                                'size-4 transition-transform',
                                open && 'rotate-180',
                            )}
                        />
                    </span>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <div className="flex flex-col gap-6 border-t border-sidebar-border/70 px-4 py-5 dark:border-sidebar-border">
                        <Explainer
                            general={copy.general}
                            learnsFrom={copy.learnsFrom}
                        />

                        <Section title="Where this page is">
                            <StageRail stage={graduation.stage} />
                        </Section>

                        <Section
                            title="What’s needed"
                            aside={`${graduation.met_count} of ${graduation.total_count} met`}
                        >
                            <RequirementChecklist
                                requirements={graduation.requirements}
                                bindingKey={graduation.binding_key}
                                onExplain={setDetail}
                            />
                        </Section>

                        <Section title="Your own model">
                            <TrainingPanel
                                graduation={graduation}
                                canManage={canManage}
                                training={actions.training}
                                onTrain={actions.train}
                                onActivate={actions.askToActivate}
                                onRevert={actions.askToRevert}
                            />
                        </Section>

                        <FieldCoverageTable
                            fields={graduation.fields}
                            employees={graduation.employees}
                        />

                        <p className="text-xs text-muted-foreground">
                            Every count here is taken straight from your records
                            each time this page loads.
                        </p>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <RequirementDialog
                requirement={detail}
                onOpenChange={(isOpen) => !isOpen && setDetail(null)}
            />

            <ConfirmDialog
                open={actions.pending !== null}
                onOpenChange={(isOpen) => !isOpen && actions.cancel()}
                title={
                    actions.pending?.kind === 'revert'
                        ? 'Switch back to the general model?'
                        : 'Switch to your own model?'
                }
                description={
                    actions.pending?.kind === 'revert'
                        ? `From the next run, the ${copy.scores} on this page come from the general model again. Your model stays on record, but using your own history again means training a new one.`
                        : `From the next run, the ${copy.scores} on this page come from the model trained on your records. Earlier runs keep saying which model scored them, and you can switch back at any time.`
                }
                confirmLabel={
                    actions.pending?.kind === 'revert'
                        ? 'Switch back'
                        : 'Switch to our model'
                }
                processing={actions.switching}
                onConfirm={actions.confirm}
            />
        </>
    );
}

function StagePill({ graduation }: { graduation: Graduation }) {
    const graduated = graduation.stage === 'graduated';

    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-[11px] font-medium',
                graduated
                    ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                    : 'bg-[#0ABFBF]/15 text-teal-700 dark:text-[#0ABFBF]',
            )}
        >
            {graduated ? 'Using your own model' : 'Using the general model'}
        </span>
    );
}

/** The one sentence a reader who never expands the panel still gets. */
function Headline({ graduation }: { graduation: Graduation }) {
    const copy = MODEL_COPY[graduation.model];

    if (graduation.stage === 'graduated') {
        return (
            <>
                These {copy.scores} come from a model trained on your
                organisation’s own records, which proved more accurate for your
                people than the general model.
            </>
        );
    }

    if (graduation.latest?.status === 'ready') {
        return (
            <>
                A model trained on your own records passed its check and is
                ready to switch to. Until someone does, these {copy.scores} come
                from the general model.
            </>
        );
    }

    if (graduation.gate_open) {
        return (
            <>
                Your records now meet every requirement — you can train a model
                of your own and see whether it beats the general one.
            </>
        );
    }

    return (
        <>
            These {copy.scores} come from a general model, built on{' '}
            {copy.general}. Once your own records meet {graduation.total_count}{' '}
            requirements, you can train one on your history instead.
            {graduation.stage === 'provisional' &&
                ' Nothing it could learn from is recorded yet.'}
        </>
    );
}

/**
 * One dot per requirement, filled when met: the "how close are we" answer at a
 * glance, with the same count in words beside it.
 */
function RequirementDots({ graduation }: { graduation: Graduation }) {
    return (
        <div className="mt-2.5 flex items-center gap-2">
            <span className="flex gap-1" aria-hidden="true">
                {graduation.requirements.map((requirement) => (
                    <span
                        key={requirement.key}
                        className={cn(
                            'h-1.5 w-5 rounded-full',
                            requirement.status === 'met'
                                ? 'bg-emerald-500'
                                : requirement.status === 'progressing'
                                  ? 'bg-amber-500/60'
                                  : 'bg-muted-foreground/25',
                        )}
                    />
                ))}
            </span>
            <span className="text-xs text-muted-foreground">
                {graduation.met_count} of {graduation.total_count} requirements
                met
            </span>
        </div>
    );
}

/** What "graduation" means, in four short points. */
function Explainer({
    general,
    learnsFrom,
}: {
    general: string;
    learnsFrom: string;
}) {
    const points: { icon: LucideIcon; title: string; body: string }[] = [
        {
            icon: BookOpen,
            title: 'Every score comes from a model',
            body: `A model is a pattern learned from past examples. The one used today learned from ${general} — not from your people.`,
        },
        {
            icon: GraduationCap,
            title: 'Graduating means learning from your own records',
            body: `Once your organisation has recorded enough of its own history, the system can train a model on ${learnsFrom}.`,
        },
        {
            icon: Scale,
            title: 'It only replaces the general model if it proves better',
            body: 'The new model is tested on your own people against the general one. It is offered only if it is clearly more accurate.',
        },
        {
            icon: ShieldCheck,
            title: 'Nothing changes until someone switches',
            body: 'Switching is a deliberate choice by someone who manages this page, and it can be undone at any time.',
        },
    ];

    return (
        <section aria-label="What is model graduation?">
            <h3 className="text-sm font-semibold">What is model graduation?</h3>
            <ul className="mt-3 grid gap-3 sm:grid-cols-2">
                {points.map(({ icon: Icon, title, body }) => (
                    <li
                        key={title}
                        className="flex gap-3 rounded-xl bg-muted/40 p-3"
                    >
                        <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-card text-teal-700 dark:text-[#0ABFBF]">
                            <Icon className="size-3.5" />
                        </span>
                        <div className="min-w-0">
                            <p className="text-sm font-medium">{title}</p>
                            <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                                {body}
                            </p>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Section({
    title,
    aside,
    children,
}: {
    title: string;
    aside?: string;
    children: React.ReactNode;
}) {
    return (
        <section aria-label={title}>
            <div className="mb-3 flex items-baseline justify-between gap-4">
                <h3 className="text-sm font-semibold">{title}</h3>
                {aside && (
                    <span className="text-xs text-muted-foreground">
                        {aside}
                    </span>
                )}
            </div>
            {children}
        </section>
    );
}

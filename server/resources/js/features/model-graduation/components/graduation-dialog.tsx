import {
    ArrowRight,
    BadgeCheck,
    BookOpen,
    FlaskConical,
    GraduationCap,
    Lock,
    Scale,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useRef } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
    ModalSection,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { formatProgress, formatShortfall, MODEL_COPY } from '../constants';
import type { Graduation, Requirement } from '../types';
import { FieldCoverageTable } from './field-coverage';
import {
    awaitsDecision,
    Headline,
    nextRequirement,
    StagePill,
} from './graduation-summary';
import { RequirementDetail } from './requirement-detail';
import { RequirementsTable } from './requirements-table';
import { StageRail } from './stage-rail';
import { TrainingPanel } from './training-panel';

export type GraduationTab = 'overview' | 'requirements' | 'model' | 'data';

/** Which tab is showing, and — on Requirements — which one is opened in full. */
export type GraduationView = {
    tab: GraduationTab;
    requirement: Requirement | null;
};

/** The tab a reader lands on: the decision when one waits, else the overview. */
export function landingTab(graduation: Graduation): GraduationTab {
    return awaitsDecision(graduation) ? 'model' : 'overview';
}

/**
 * Model graduation in full, as a centred modal (ADR 0046). The page keeps a
 * one-line strip; everything a reader might need sits here, one question per
 * tab:
 *
 *  - Overview      — what graduation is, where this page is, and the next step;
 *  - Requirements  — every requirement as a table, each opening in full;
 *  - Your model    — the model in use, one ready to switch to, a failed check;
 *  - Data used     — the fields the scores read, and how complete they are.
 *
 * Training starts from the footer, so it is in view whichever tab is open.
 */
export function GraduationDialog({
    graduation,
    canManage,
    view,
    onView,
    onClose,
    training,
    onTrain,
    onActivate,
    onRevert,
}: {
    graduation: Graduation;
    canManage: boolean;
    view: GraduationView | null;
    onView: (view: GraduationView) => void;
    onClose: () => void;
    training: boolean;
    onTrain: () => void;
    onActivate: (hashid: string) => void;
    onRevert: () => void;
}) {
    const copy = MODEL_COPY[graduation.model];
    const tab = view?.tab ?? 'overview';
    const tabs: { value: GraduationTab; label: string; aside?: string }[] = [
        { value: 'overview', label: 'Overview' },
        {
            value: 'requirements',
            label: 'Requirements',
            aside: `${graduation.met_count}/${graduation.total_count}`,
        },
        { value: 'model', label: 'Your model' },
        {
            value: 'data',
            label: 'Data used',
            aside: String(graduation.fields.length),
        },
    ];

    const go = (next: GraduationTab) =>
        onView({ tab: next, requirement: null });

    return (
        <Modal open={view !== null} onOpenChange={(open) => !open && onClose()}>
            <ModalContent
                size="2xl"
                className="sm:h-[min(46rem,calc(100dvh-4rem))]"
            >
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <GraduationCap />
                        </ModalIcon>
                    }
                    title="Model graduation"
                    description={<Headline graduation={graduation} />}
                    meta={
                        <>
                            <span className="text-xs font-medium">
                                {copy.page}
                            </span>
                            <StagePill graduation={graduation} />
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {graduation.met_count} of{' '}
                                {graduation.total_count} requirements met ·{' '}
                                {graduation.examples.toLocaleString()} examples
                                on record
                            </span>
                        </>
                    }
                />

                <TabStrip tabs={tabs} value={tab} onChange={go} />

                <ModalBody
                    id={`graduation-panel-${tab}`}
                    role="tabpanel"
                    aria-labelledby={`graduation-tab-${tab}`}
                    className="py-4"
                >
                    {tab === 'overview' && (
                        <Overview graduation={graduation} onGo={go} />
                    )}

                    {tab === 'requirements' &&
                        (view?.requirement ? (
                            <RequirementDetail
                                requirement={view.requirement}
                                onBack={() => go('requirements')}
                            />
                        ) : (
                            <div className="flex flex-col gap-3">
                                <p className="text-sm text-muted-foreground">
                                    Training unlocks once every requirement is
                                    met. Open one to see what to do about it,
                                    and why its threshold is that number.
                                </p>
                                <RequirementsTable
                                    requirements={graduation.requirements}
                                    bindingKey={graduation.binding_key}
                                    onOpen={(requirement) =>
                                        onView({
                                            tab: 'requirements',
                                            requirement,
                                        })
                                    }
                                />
                            </div>
                        ))}

                    {tab === 'model' && (
                        <TrainingPanel
                            graduation={graduation}
                            canManage={canManage}
                            onActivate={onActivate}
                            onRevert={onRevert}
                        />
                    )}

                    {tab === 'data' && (
                        <FieldCoverageTable
                            fields={graduation.fields}
                            employees={graduation.employees}
                        />
                    )}
                </ModalBody>

                <ModalFooter className="justify-between">
                    <p className="text-xs text-muted-foreground">
                        Counted from your records each time this page loads.
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" onClick={onClose}>
                            Close
                        </Button>
                        {canManage && (
                            <TrainButton
                                graduation={graduation}
                                training={training}
                                onTrain={onTrain}
                            />
                        )}
                    </div>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}

/**
 * Starts training — the one action graduation has before a model exists.
 * Locked (and saying so) until every requirement is met; secondary while a
 * model that already passed is waiting to be switched to.
 */
function TrainButton({
    graduation,
    training,
    onTrain,
}: {
    graduation: Graduation;
    training: boolean;
    onTrain: () => void;
}) {
    const { gate_open, latest } = graduation;
    const primary = gate_open && latest?.status !== 'ready';

    return (
        <Button
            size="sm"
            onClick={onTrain}
            disabled={!gate_open || training}
            variant={primary ? 'default' : 'outline'}
            title={
                gate_open ? undefined : 'Unlocks when every requirement is met'
            }
        >
            {training ? (
                <Spinner />
            ) : gate_open ? (
                <FlaskConical className="size-3.5" />
            ) : (
                <Lock className="size-3.5" />
            )}
            {training ? 'Training and checking…' : 'Train on our records'}
        </Button>
    );
}

/**
 * The tabs. A real tablist: arrow keys move between tabs, Home/End jump to the
 * ends, and only the active tab is in the page's tab order.
 */
function TabStrip({
    tabs,
    value,
    onChange,
}: {
    tabs: { value: GraduationTab; label: string; aside?: string }[];
    value: GraduationTab;
    onChange: (tab: GraduationTab) => void;
}) {
    const strip = useRef<HTMLDivElement>(null);

    const move = (event: KeyboardEvent) => {
        const index = tabs.findIndex((t) => t.value === value);

        const next = {
            ArrowRight: (index + 1) % tabs.length,
            ArrowLeft: (index - 1 + tabs.length) % tabs.length,
            Home: 0,
            End: tabs.length - 1,
        }[event.key];

        if (next === undefined) {
            return;
        }

        event.preventDefault();
        onChange(tabs[next].value);
        strip.current
            ?.querySelectorAll<HTMLButtonElement>('[role="tab"]')
            [next]?.focus();
    };

    return (
        <div
            ref={strip}
            role="tablist"
            aria-label="Model graduation sections"
            onKeyDown={move}
            className="flex shrink-0 gap-1 overflow-x-auto border-b border-border px-4 pt-1 sm:px-5"
        >
            {tabs.map((t) => {
                const active = t.value === value;

                return (
                    <button
                        key={t.value}
                        id={`graduation-tab-${t.value}`}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        aria-controls={`graduation-panel-${t.value}`}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(t.value)}
                        className={cn(
                            'relative inline-flex shrink-0 items-center gap-1.5 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            active
                                ? 'text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {t.label}
                        {t.aside && (
                            <span className="rounded-full bg-muted px-1.5 text-[11px] text-muted-foreground tabular-nums">
                                {t.aside}
                            </span>
                        )}
                        {active && (
                            <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-[#0ABFBF]" />
                        )}
                    </button>
                );
            })}
        </div>
    );
}

// ── Overview ─────────────────────────────────────────────────────────────────

function Overview({
    graduation,
    onGo,
}: {
    graduation: Graduation;
    onGo: (tab: GraduationTab) => void;
}) {
    const copy = MODEL_COPY[graduation.model];

    return (
        <div className="flex flex-col gap-6">
            <NextStep graduation={graduation} onGo={onGo} />

            <ModalSection title="Where this page is">
                <StageRail stage={graduation.stage} />
            </ModalSection>

            <ModalSection title="What is model graduation?">
                <Explainer
                    general={copy.general}
                    learnsFrom={copy.learnsFrom}
                />
            </ModalSection>
        </div>
    );
}

/** The single thing to do next, with the tab that does it. */
function NextStep({
    graduation,
    onGo,
}: {
    graduation: Graduation;
    onGo: (tab: GraduationTab) => void;
}) {
    const next = nextRequirement(graduation);

    let step: {
        icon: LucideIcon;
        title: string;
        body: ReactNode;
        action: string;
        tab: GraduationTab;
        emphasis: boolean;
    };

    if (graduation.latest?.status === 'ready') {
        step = {
            icon: Sparkles,
            title: 'A model trained on your records is ready',
            body: 'It passed its check against the general model on your own people. Review the result, then switch to it — or keep the general model.',
            action: 'Review the new model',
            tab: 'model',
            emphasis: true,
        };
    } else if (graduation.stage === 'graduated') {
        step = {
            icon: BadgeCheck,
            title: 'Your own model is in use',
            body: 'See how it compared with the general model, or switch back at any time.',
            action: 'See your model',
            tab: 'model',
            emphasis: false,
        };
    } else if (graduation.gate_open) {
        step = {
            icon: FlaskConical,
            title: 'Every requirement is met',
            body: 'Your records can now train a model of your own. It is checked against the general model and offered only if it is clearly more accurate.',
            action: 'Train your own model',
            tab: 'model',
            emphasis: true,
        };
    } else {
        const shortfall = next ? formatShortfall(next) : null;

        step = {
            icon: ArrowRight,
            title: next
                ? `${next.key === graduation.binding_key ? 'Furthest to go' : 'Still needed'}: ${next.label}`
                : 'Some requirements are still to meet',
            body: next ? (
                <>
                    {formatProgress(next)}
                    {shortfall && (
                        <>
                            {' '}
                            —{' '}
                            <span className="font-medium text-foreground">
                                {shortfall}
                            </span>
                        </>
                    )}
                    . {next.action}
                    {next.outlook && (
                        <span className="mt-1 block">{next.outlook}</span>
                    )}
                </>
            ) : (
                'Open the requirements to see what is still missing.'
            ),
            action: 'See every requirement',
            tab: 'requirements',
            emphasis: false,
        };
    }

    const Icon = step.icon;

    return (
        <div
            className={cn(
                'flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:items-center',
                step.emphasis
                    ? 'border-[#0ABFBF]/40 bg-[#0ABFBF]/6'
                    : 'border-sidebar-border/70 bg-muted/30 dark:border-sidebar-border',
            )}
        >
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/15 text-teal-700 dark:text-[#0ABFBF]">
                <Icon className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{step.title}</p>
                <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                    {step.body}
                </p>
            </div>
            <Button
                size="sm"
                variant={step.emphasis ? 'default' : 'outline'}
                className="shrink-0"
                onClick={() => onGo(step.tab)}
            >
                {step.action}
                <ArrowRight className="size-3.5" />
            </Button>
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
        <ul className="grid gap-2.5 sm:grid-cols-2">
            {points.map(({ icon: Icon, title, body }) => (
                <li
                    key={title}
                    className="flex gap-3 rounded-xl bg-muted/40 px-3 py-2.5"
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
    );
}

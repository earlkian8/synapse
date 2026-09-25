import { useForm } from '@inertiajs/react';
import { ArrowLeft, PencilRuler } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PipelinesManager } from '@/features/recruitment-pipelines/components/pipelines-manager';
import {
    KIND_DOT,
    KIND_LABELS,
} from '@/features/recruitment-pipelines/constants';
import type {
    PipelinesPageProps,
    StageDraft,
} from '@/features/recruitment-pipelines/types';
import { setupWizardRoutes } from '../routes';
import type { PipelineBlueprint, StepControls } from '../types';
import ChoiceCard from './choice-card';
import SectionHeading from './section-heading';
import StageEditor from './stage-editor';
import StepBody from './step-body';
import StepFooter, { continueAction } from './step-footer';
import SuggestionsPanel, { useSuggestionsOpen } from './suggestions-panel';

type Props = StepControls & {
    screen: PipelinesPageProps;
    blueprints: PipelineBlueprint[];
};

/**
 * The shortest process that still works: somewhere to start, somewhere to be
 * hired, somewhere not to be. A company drawing its own starts here rather than
 * at an empty list, because an empty list is a puzzle and this is a first draft.
 */
const FROM_SCRATCH: StageDraft[] = [
    { name: 'Applied', kind: 'open' },
    { name: 'Hired', kind: 'won' },
    { name: 'Not proceeding', kind: 'lost' },
];

/**
 * The hiring process. Each option shows its actual stages rather than a count,
 * because the stages are the whole difference between them.
 *
 * A company whose hiring looks like none of them draws its own: **Customise
 * these stages** opens the chosen shape up for editing, and **Design your own**
 * starts from the three stages every process needs. Either way what is created
 * is an ordinary pipeline the Recruitment Pipelines screen reads back — the
 * wizard has no lesser kind.
 *
 * The first pipeline a company has becomes its default. The Recruitment
 * Pipelines editor sits under the suggestions for everything after — a company
 * that hires interns and executives differently usually wants one process each.
 */
export default function RecruitmentStep({
    screen,
    blueprints,
    ...controls
}: Props) {
    const [open, setOpen] = useSuggestionsOpen(controls.configured);
    // Which button started the save — the tray's (add, and stay) or the
    // footer's (add, and continue) — so only that one spins.
    const [continuing, setContinuing] = useState(false);

    const { data, setData, post, processing, errors, clearErrors, reset } =
        useForm({
            source: 'blueprint' as 'blueprint' | 'custom',
            blueprint: blueprints[0]?.key ?? '',
            name: '',
            stages: [] as StageDraft[],
        });

    const chosen = blueprints.find(
        (blueprint) => blueprint.key === data.blueprint,
    );

    const drawing = data.source === 'custom';

    /** Open a shape up for editing — or start from the shortest one that works. */
    const draw = (from: PipelineBlueprint | null) => {
        clearErrors();

        setData((current) => ({
            ...current,
            source: 'custom',
            name: current.name.trim() || (from?.name ?? ''),
            stages: (from?.stages ?? FROM_SCRATCH).map((stage) => ({
                ...stage,
            })),
        }));
    };

    const adopt = () => {
        clearErrors();
        setData((current) => ({ ...current, source: 'blueprint', stages: [] }));
    };

    /** Create the process — then stay to see it among the others, or move on. */
    const submit = (andContinue: boolean) => {
        setContinuing(andContinue);

        post(setupWizardRoutes.recruitment, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setContinuing(false),
            onSuccess: () => {
                reset();

                if (andContinue) {
                    controls.onNext();
                } else {
                    setOpen(false);
                }
            },
        });
    };

    const messages = errors as Record<string, string>;

    const name = drawing ? data.name.trim() : data.name.trim() || chosen?.name;
    const ready = drawing
        ? data.name.trim() !== '' && data.stages.length > 0
        : data.blueprint !== '';
    const pending = open && ready;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <StepBody>
                <SuggestionsPanel
                    open={open}
                    onOpenChange={setOpen}
                    collapsible={controls.configured}
                    description="Pick the shape closest to how you hire, customise its stages, or draw your own."
                    summary={`${blueprints.length} common hiring processes, or draw your own`}
                    onSubmit={() => submit(false)}
                    submitLabel={name ? `Create "${name}"` : 'Create process'}
                    submitDisabled={!ready}
                    processing={processing && !continuing}
                >
                    {drawing ? (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 className="text-sm font-semibold text-foreground">
                                        Your hiring process
                                    </h2>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Name each stage the way your team
                                        already says it, and say what it means.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={adopt}
                                    className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                >
                                    <ArrowLeft className="size-3.5" />
                                    Back to the suggestions
                                </button>
                            </div>

                            <div className="max-w-sm">
                                <Label
                                    htmlFor="pipeline-name"
                                    className="mb-1.5 block"
                                >
                                    What is this process called?
                                </Label>
                                <Input
                                    id="pipeline-name"
                                    value={data.name}
                                    onChange={(event) =>
                                        setData('name', event.target.value)
                                    }
                                    placeholder="e.g. Warehouse Hiring"
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-1.5"
                                />
                            </div>

                            <StageEditor
                                stages={data.stages}
                                errors={messages}
                                onChange={(stages) => {
                                    clearErrors('stages');
                                    setData('stages', stages);
                                }}
                            />
                        </>
                    ) : (
                        <>
                            <div className="flex flex-col gap-2.5">
                                {blueprints.map((blueprint) => (
                                    <ChoiceCard
                                        key={blueprint.key}
                                        mode="single"
                                        name="pipeline"
                                        value={blueprint.key}
                                        checked={
                                            data.blueprint === blueprint.key
                                        }
                                        onChange={() => {
                                            clearErrors('blueprint');
                                            setData('blueprint', blueprint.key);
                                        }}
                                        title={blueprint.name}
                                        description={blueprint.description}
                                        aside={`${blueprint.stages.length} stages`}
                                        action={
                                            data.blueprint === blueprint.key ? (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        draw(blueprint)
                                                    }
                                                    className="mt-1 ml-7 text-[11px] font-medium text-[#0a8b91] underline-offset-4 hover:underline dark:text-[#0ABFBF]"
                                                >
                                                    Customise these stages
                                                </button>
                                            ) : undefined
                                        }
                                    >
                                        <span className="mt-1 flex flex-wrap items-center gap-1.5 pl-7">
                                            {blueprint.stages.map(
                                                (stage, index) => (
                                                    <span
                                                        key={stage.name}
                                                        className="flex items-center gap-1.5"
                                                    >
                                                        {index > 0 && (
                                                            <span
                                                                aria-hidden
                                                                className="text-muted-foreground/40"
                                                            >
                                                                ›
                                                            </span>
                                                        )}
                                                        <span
                                                            title={
                                                                KIND_LABELS[
                                                                    stage.kind
                                                                ]
                                                            }
                                                            className="inline-flex items-center gap-1.5 rounded-full border border-sidebar-border/70 bg-background px-2 py-0.5 text-[11px] text-foreground dark:border-sidebar-border"
                                                        >
                                                            <span
                                                                aria-hidden
                                                                className={`size-1.5 rounded-full ${KIND_DOT[stage.kind]}`}
                                                            />
                                                            {stage.name}
                                                        </span>
                                                    </span>
                                                ),
                                            )}
                                        </span>
                                    </ChoiceCard>
                                ))}

                                <button
                                    type="button"
                                    onClick={() => draw(null)}
                                    className="flex items-start gap-3 rounded-xl border border-dashed border-sidebar-border bg-muted/20 p-4 text-left transition-colors hover:border-[#0ABFBF]/50 hover:bg-[#0ABFBF]/[0.03] focus-visible:ring-2 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none"
                                >
                                    <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-background text-muted-foreground">
                                        <PencilRuler className="size-4" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold text-foreground">
                                            Design your own
                                        </span>
                                        <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                                            Draw the stages your company
                                            actually moves candidates through,
                                            in your own words.
                                        </span>
                                    </span>
                                </button>
                            </div>

                            <InputError message={errors.blueprint} />

                            <div className="max-w-sm">
                                <Label
                                    htmlFor="pipeline-name"
                                    className="mb-1.5 block"
                                >
                                    Call it something else{' '}
                                    <span className="text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="pipeline-name"
                                    value={data.name}
                                    onChange={(event) =>
                                        setData('name', event.target.value)
                                    }
                                    placeholder={
                                        chosen?.name ?? 'Standard Hiring'
                                    }
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-1.5"
                                />
                            </div>
                        </>
                    )}

                    <p className="text-xs leading-relaxed text-muted-foreground">
                        What recruitment reads is a stage's meaning rather than
                        its name — exactly one stage means hired, at least one
                        means the candidate did not go through, and the rest are
                        in progress.
                    </p>
                </SuggestionsPanel>

                <PipelinesManager
                    {...screen}
                    heading={
                        <SectionHeading
                            title="Your hiring processes"
                            hint="Rename, reorder and add stages, or change which process a new job posting runs on by default."
                        />
                    }
                />
            </StepBody>

            <StepFooter
                onBack={controls.onBack}
                onSkip={controls.onSkip}
                busy={controls.busy}
                skipping={controls.skipping}
                primary={
                    pending
                        ? {
                              label: `Create "${name}" and continue`,
                              onClick: () => submit(true),
                              processing: processing && continuing,
                              disabled: processing,
                          }
                        : continueAction(controls)
                }
                note={
                    pending || controls.configured
                        ? undefined
                        : 'Create a hiring process, or skip this step for now.'
                }
            />
        </div>
    );
}

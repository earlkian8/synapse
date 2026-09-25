import { useForm } from '@inertiajs/react';
import { ArrowLeft, CalendarRange, PencilRuler, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { KpiManager } from '@/features/kpi-config/components/kpi-manager';
import { PeriodModal } from '@/features/kpi-config/components/period-modal';
import type { KpiSetupPageProps } from '@/features/kpi-config/types';
import type { RatingBand } from '@/features/performance/types';
import type { BandTone } from '@/features/performance/types';
import { setupWizardRoutes } from '../routes';
import type {
    CriterionBlueprint,
    FrameworkBlueprint,
    FrameworkDraft,
    FrameworkItemDraft,
    InstrumentBlueprint,
    StepControls,
} from '../types';
import ChoiceCard from './choice-card';
import FrameworkEditor from './framework-editor';
import SectionHeading from './section-heading';
import StepBody from './step-body';
import StepFooter, { continueAction } from './step-footer';
import SuggestionsPanel, { useSuggestionsOpen } from './suggestions-panel';

type Props = StepControls & {
    screen: KpiSetupPageProps;
    blueprints: FrameworkBlueprint[];
    criteria: CriterionBlueprint[];
    instruments: InstrumentBlueprint[];
    bands: RatingBand[];
    tones: BandTone[];
};

/**
 * How the company reviews its people.
 *
 * A framework is a whole apparatus: weighted sections, the criteria inside them,
 * and the instruments those are measured on (ADR 0028). Building one from
 * nothing is the single heaviest thing in Company Setup, so here it is picked —
 * and the card shows the sections and the actual questions, not a count, because
 * that is what the choice is between.
 *
 * But an appraisal is also the most company-specific thing in the system, so
 * picking is not the only way through. **Customise this framework** opens the
 * chosen one up — rename its sections, reweight them, swap a criterion, write
 * one nothing on the list covers — and **Design your own** starts from a single
 * empty section. Both produce an ordinary framework the Performance Framework
 * editor reads back unchanged — and that editor sits under the suggestions,
 * with the rating scales, the criteria catalogue and the review cycles an
 * appraisal is conducted in.
 */
export default function PerformanceStep({
    screen,
    blueprints,
    criteria,
    instruments,
    bands,
    tones,
    ...controls
}: Props) {
    const [open, setOpen] = useSuggestionsOpen(controls.configured);
    // Which button started the save — the tray's (add, and stay) or the
    // footer's (add, and continue) — so only that one spins.
    const [continuing, setContinuing] = useState(false);
    const [addingCycle, setAddingCycle] = useState(false);

    const {
        data,
        setData,
        post,
        processing,
        errors,
        clearErrors,
        transform,
        reset,
    } = useForm({
        source: 'blueprint' as 'blueprint' | 'custom',
        blueprint: blueprints[0]?.key ?? '',
        name: '',
        draft: emptyDraft(instruments, bands),
    });

    const chosen = blueprints.find(
        (blueprint) => blueprint.key === data.blueprint,
    );

    const designing = data.source === 'custom';

    /** Open a framework up for editing — or start from one empty section. */
    const design = (from: FrameworkBlueprint | null) => {
        clearErrors();

        setData((current) => ({
            ...current,
            source: 'custom',
            draft:
                from === null
                    ? emptyDraft(instruments, bands)
                    : draftFrom(from, current.name, bands),
        }));
    };

    const adopt = () => {
        clearErrors();
        setData((current) => ({ ...current, source: 'blueprint' }));
    };

    /** Create the framework — then stay to add a review cycle, or move on. */
    const submit = (andContinue: boolean) => {
        setContinuing(andContinue);

        // The two answers post the same field names: a blueprint key and an
        // optional rename, or the framework itself.
        transform((payload) =>
            payload.source === 'custom'
                ? {
                      source: 'custom',
                      name: payload.draft.name,
                      description: payload.draft.description,
                      scale: payload.draft.scale,
                      result_display: payload.draft.result_display,
                      sections: payload.draft.sections,
                      items: payload.draft.items.map((item) => ({
                          section: item.section,
                          weight: item.weight,
                          criterion: item.criterion,
                          name: item.name,
                          description: item.description,
                          scale: item.scale,
                      })),
                      bands: payload.draft.bands,
                  }
                : {
                      source: 'blueprint',
                      blueprint: payload.blueprint,
                      name: payload.name,
                  },
        );

        post(setupWizardRoutes.performance, {
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

    const name = designing
        ? data.draft.name.trim()
        : data.name.trim() || chosen?.name;
    const ready = designing
        ? data.draft.name.trim() !== '' && data.draft.items.length > 0
        : data.blueprint !== '';
    const pending = open && ready;

    // A framework says what an appraisal measures; a cycle is when one happens.
    // Without a cycle, nothing can be appraised yet.
    const needsCycle =
        screen.templates.length > 0 && screen.periods.length === 0;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <StepBody>
                <SuggestionsPanel
                    open={open}
                    onOpenChange={setOpen}
                    collapsible={controls.configured}
                    description="Each framework shows its sections and every criterion. Pick one, customise it, or design your own."
                    summary={`${blueprints.length} ready-made frameworks, or design your own`}
                    onSubmit={() => submit(false)}
                    submitLabel={name ? `Create "${name}"` : 'Create framework'}
                    submitDisabled={!ready}
                    processing={processing && !continuing}
                >
                    {designing ? (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 className="text-sm font-semibold text-foreground">
                                        Your appraisal
                                    </h2>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Everything here is editable afterwards
                                        under Company Setup.
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

                            <FrameworkEditor
                                draft={data.draft}
                                criteria={criteria}
                                instruments={instruments}
                                tones={tones}
                                errors={messages}
                                onChange={(patch) =>
                                    setData((current) => ({
                                        ...current,
                                        draft: { ...current.draft, ...patch },
                                    }))
                                }
                            />
                        </>
                    ) : (
                        <>
                            <div className="flex flex-col gap-2.5">
                                {blueprints.map((blueprint) => {
                                    const selected =
                                        data.blueprint === blueprint.key;

                                    return (
                                        <ChoiceCard
                                            key={blueprint.key}
                                            mode="single"
                                            name="framework"
                                            value={blueprint.key}
                                            checked={selected}
                                            onChange={() => {
                                                clearErrors('blueprint');
                                                setData(
                                                    'blueprint',
                                                    blueprint.key,
                                                );
                                            }}
                                            title={blueprint.name}
                                            description={blueprint.description}
                                            aside={`${blueprint.items.length} measures`}
                                            action={
                                                selected ? (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            design(blueprint)
                                                        }
                                                        className="mt-2 ml-7 text-[11px] font-medium text-[#0a8b91] underline-offset-4 hover:underline dark:text-[#0ABFBF]"
                                                    >
                                                        Customise this framework
                                                    </button>
                                                ) : undefined
                                            }
                                        >
                                            <span className="mt-2 flex flex-col gap-2 pl-7">
                                                {blueprint.sections.map(
                                                    (section) => (
                                                        <span
                                                            key={section.key}
                                                            className="block rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 dark:border-sidebar-border"
                                                        >
                                                            <span className="flex items-baseline justify-between gap-3">
                                                                <span className="text-xs font-semibold text-foreground">
                                                                    {
                                                                        section.name
                                                                    }
                                                                </span>
                                                                <span className="shrink-0 text-[11px] text-[#0a8b91] tabular-nums dark:text-[#0ABFBF]">
                                                                    {
                                                                        section.weight
                                                                    }
                                                                    % of the
                                                                    result
                                                                </span>
                                                            </span>

                                                            {selected && (
                                                                <span className="mt-1.5 flex flex-col gap-1">
                                                                    {blueprint.items
                                                                        .filter(
                                                                            (
                                                                                item,
                                                                            ) =>
                                                                                item.section ===
                                                                                section.key,
                                                                        )
                                                                        .map(
                                                                            (
                                                                                item,
                                                                            ) => (
                                                                                <span
                                                                                    key={
                                                                                        item.name
                                                                                    }
                                                                                    className="flex items-baseline justify-between gap-3 text-[11px]"
                                                                                >
                                                                                    <span className="text-muted-foreground">
                                                                                        {
                                                                                            item.name
                                                                                        }
                                                                                        <span className="px-1.5 text-muted-foreground/40">
                                                                                            ·
                                                                                        </span>
                                                                                        <span className="text-muted-foreground/70">
                                                                                            {
                                                                                                item.scale
                                                                                            }
                                                                                        </span>
                                                                                    </span>
                                                                                    <span className="shrink-0 text-muted-foreground/70 tabular-nums">
                                                                                        {
                                                                                            item.weight
                                                                                        }

                                                                                        %
                                                                                        of
                                                                                        section
                                                                                    </span>
                                                                                </span>
                                                                            ),
                                                                        )}
                                                                </span>
                                                            )}
                                                        </span>
                                                    ),
                                                )}
                                            </span>
                                        </ChoiceCard>
                                    );
                                })}

                                <button
                                    type="button"
                                    onClick={() => design(null)}
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
                                            Write the sections, weights and
                                            criteria your appraisal actually
                                            asks about.
                                        </span>
                                    </span>
                                </button>
                            </div>

                            <InputError message={errors.blueprint} />

                            <div className="max-w-sm">
                                <Label
                                    htmlFor="framework-title"
                                    className="mb-1.5 block"
                                >
                                    Call it something else{' '}
                                    <span className="text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="framework-title"
                                    value={data.name}
                                    onChange={(event) =>
                                        setData('name', event.target.value)
                                    }
                                    placeholder={
                                        chosen?.name ?? 'Balanced Appraisal'
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
                        This also fills your criteria catalogue and the rating
                        scales behind it, so the framework is editable the
                        moment it exists — reweight a section, swap a criterion,
                        or write a new one below.
                    </p>
                </SuggestionsPanel>

                {needsCycle && (
                    <div className="flex flex-col gap-3 rounded-xl border border-[#0ABFBF]/30 bg-[#0ABFBF]/[0.06] p-4 sm:flex-row sm:items-center">
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background text-[#0a8b91] dark:text-[#0ABFBF]">
                            <CalendarRange className="size-4" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-foreground">
                                Add the review cycle you’ll appraise in
                            </p>
                            <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                                A framework says what an appraisal measures; a
                                cycle is when one happens. Until there is one,
                                nobody can be appraised.
                            </p>
                        </div>
                        {screen.can.manage && (
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => setAddingCycle(true)}
                                className="shrink-0"
                            >
                                <Plus className="size-4" />
                                Add a review cycle
                            </Button>
                        )}
                    </div>
                )}

                <SectionHeading
                    title="Your performance framework"
                    hint="Frameworks, the rating scales they measure on, the criteria they draw from, and the review cycles appraisals run in — everything the Performance Framework screen offers."
                />

                <KpiManager {...screen} />

                <PeriodModal
                    period={null}
                    open={addingCycle}
                    onOpenChange={setAddingCycle}
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
                        : 'Create a framework, or skip this step for now.'
                }
            />
        </div>
    );
}

/**
 * A framework with one section and nothing in it — what "design your own" opens
 * on. One section rather than none, because a framework with no sections cannot
 * be added to, and the first decision worth making is what to measure rather
 * than how to divide it up.
 */
function emptyDraft(
    instruments: InstrumentBlueprint[],
    bands: RatingBand[],
): FrameworkDraft {
    return {
        name: '',
        description: '',
        scale: instruments[0]?.name ?? '',
        result_display: 'band',
        sections: [
            {
                key: 'section_1',
                name: 'Performance',
                description: '',
                weight: 100,
            },
        ],
        items: [],
        bands: bands.map((band) => ({ ...band })),
    };
}

/**
 * A framework blueprint opened up for editing. Its lines stay linked to the
 * criteria catalogue rather than becoming copies of their wording, so a company
 * that changes the weights but keeps the questions still measures what everyone
 * else measures.
 */
function draftFrom(
    blueprint: FrameworkBlueprint,
    renamedTo: string,
    bands: RatingBand[],
): FrameworkDraft {
    return {
        name: renamedTo.trim() || blueprint.name,
        description: blueprint.description,
        scale: blueprint.scale,
        result_display:
            blueprint.result_display as FrameworkDraft['result_display'],
        sections: blueprint.sections.map((section) => ({
            key: section.key,
            name: section.name,
            description: section.description,
            weight: section.weight,
        })),
        items: blueprint.items.map((item, index): FrameworkItemDraft => ({
            id: `item_${index}`,
            section: item.section,
            weight: item.weight,
            criterion: item.criterion,
            name: item.name,
            description: item.description,
            scale: item.scale,
        })),
        bands: bands.map((band) => ({ ...band })),
    };
}

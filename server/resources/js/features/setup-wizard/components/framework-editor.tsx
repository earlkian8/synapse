import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import type { SelectOption } from '@/components/form-select';
import { FormSelect } from '@/components/form-select';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    PercentInput,
    roundWeight,
    shareOf,
    splitEvenly,
    WeightTally,
} from '@/features/kpi-config/components/weight-controls';
import { RESULT_DISPLAYS } from '@/features/kpi-config/constants';
import { RatingLadder } from '@/features/performance/components/rating-ladder';
import { bandTone } from '@/features/performance/constants';
import type {
    BandTone,
    RatingBand,
    ResultDisplay,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';
import type {
    CriterionBlueprint,
    FrameworkDraft,
    FrameworkItemDraft,
    InstrumentBlueprint,
    SectionDraft,
} from '../types';

/** The picker value standing for a criterion the company writes itself. */
const OWN = 'own';

type Props = {
    draft: FrameworkDraft;
    criteria: CriterionBlueprint[];
    instruments: InstrumentBlueprint[];
    tones: BandTone[];
    errors: Record<string, string>;
    onChange: (patch: Partial<FrameworkDraft>) => void;
};

/**
 * The framework a company designs for itself, inside the wizard.
 *
 * It asks the same three questions the Performance Framework editor asks —
 * what an appraisal measures, on what instrument, and in what words a result is
 * reported — and produces a framework that editor reads back unchanged. What it
 * leaves out is the eligibility rule: on day one a framework applies to
 * everyone, because there is nobody to divide yet.
 *
 * A criterion is **chosen, not typed**, wherever the catalogue already says what
 * the company means; writing one is an explicit choice with its own fields
 * (ADR 0028). The difference matters afterwards rather than now: a criterion
 * drawn from the catalogue is worded the same in every framework that measures
 * it, so reporting across frameworks can compare like with like.
 *
 * Weights are relative twice over — a section's share of the appraisal, and a
 * line's share of its section — which is the part people misread, so each line
 * also states what it ends up worth overall.
 */
export default function FrameworkEditor({
    draft,
    criteria,
    instruments,
    tones,
    errors,
    onChange,
}: Props) {
    const [showBands, setShowBands] = useState(false);

    // Sections, lines and ratings are all positional, so each needs an identity
    // of its own to be reordered and removed by. A counter gives one without
    // reading the clock during a render — which the compiler rightly refuses.
    const [sequence, setSequence] = useState(
        () => draft.sections.length + draft.items.length + draft.bands.length,
    );

    /** The next unused key, and the promise never to hand it out again. */
    const nextKey = (prefix: string): string => {
        setSequence((current) => current + 1);

        return `${prefix}_${sequence}`;
    };

    const instrumentOptions: SelectOption[] = instruments.map((instrument) => ({
        value: instrument.name,
        label: `${instrument.name} · ${instrument.descriptor}`,
    }));

    const catalogue = new Map(
        criteria.map((criterion) => [criterion.key, criterion]),
    );

    // A criterion belongs to one line of a framework: asking the same question
    // twice only splits its weight in two.
    const used = new Set(
        draft.items
            .map((item) => item.criterion)
            .filter((key): key is string => key !== null),
    );

    const sectionTotal = draft.sections.reduce(
        (sum, section) => sum + (section.weight || 0),
        0,
    );

    const patchSection = (index: number, patch: Partial<SectionDraft>) =>
        onChange({
            sections: draft.sections.map((section, at) =>
                at === index ? { ...section, ...patch } : section,
            ),
        });

    const removeSection = (index: number) => {
        const removed = draft.sections[index];

        onChange({
            sections: draft.sections.filter((_, at) => at !== index),
            // Lines would otherwise point at a section that no longer exists.
            items: draft.items.filter((item) => item.section !== removed.key),
        });
    };

    const patchItem = (id: string, patch: Partial<FrameworkItemDraft>) =>
        onChange({
            items: draft.items.map((item) =>
                item.id === id ? { ...item, ...patch } : item,
            ),
        });

    /**
     * A line built from a picker choice. A catalogue line takes the catalogue's
     * wording, meaning and instrument; a line the company writes starts empty on
     * the framework's own instrument. A weight already on the line is kept — it
     * was set against the other lines in the section, not against the criterion.
     */
    const lineFor = (
        choice: string,
        section: string,
        current?: FrameworkItemDraft,
    ): FrameworkItemDraft => {
        const criterion = choice === OWN ? null : catalogue.get(choice);

        return {
            id: current?.id ?? nextKey('item'),
            section,
            weight: current?.weight || criterion?.weight || 0,
            criterion: criterion?.key ?? null,
            name: criterion?.name ?? '',
            description: criterion?.description ?? '',
            scale: criterion?.scale ?? draft.scale,
        };
    };

    const patchBand = (index: number, patch: Partial<RatingBand>) =>
        onChange({
            bands: draft.bands.map((band, at) =>
                at === index ? { ...band, ...patch } : band,
            ),
        });

    return (
        <div className="flex flex-col gap-5">
            {/* ── What it is ─────────────────────────────────────────────── */}
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="framework-name" className="mb-1.5 block">
                        What is this framework called?
                    </Label>
                    <Input
                        id="framework-name"
                        value={draft.name}
                        onChange={(event) =>
                            onChange({ name: event.target.value })
                        }
                        placeholder="e.g. Crew Review"
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div>
                    <Label htmlFor="framework-scale" className="mb-1.5 block">
                        Measured on
                    </Label>
                    <FormSelect
                        id="framework-scale"
                        value={draft.scale}
                        onChange={(scale) => onChange({ scale })}
                        options={instrumentOptions}
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        The instrument a criterion falls back to when it names
                        none of its own.
                    </p>
                    <InputError message={errors.scale} className="mt-1.5" />
                </div>
            </div>

            <div>
                <Label htmlFor="framework-description" className="mb-1.5 block">
                    What it is for{' '}
                    <span className="text-muted-foreground">(optional)</span>
                </Label>
                <Input
                    id="framework-description"
                    value={draft.description}
                    onChange={(event) =>
                        onChange({ description: event.target.value })
                    }
                    placeholder="Who it reviews, and what it is trying to find out."
                />
            </div>

            {/* ── What it measures ───────────────────────────────────────── */}
            <div>
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 className="text-sm font-semibold text-foreground">
                            What it measures
                        </h2>
                        <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                            Sections divide the appraisal between them; the
                            criteria inside a section divide the section.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={draft.sections.length >= 10}
                        onClick={() =>
                            onChange({
                                sections: [
                                    ...draft.sections,
                                    {
                                        key: nextKey('section'),
                                        name: '',
                                        description: '',
                                        weight: 0,
                                    },
                                ],
                            })
                        }
                    >
                        <Plus className="size-4" />
                        Section
                    </Button>
                </div>

                <div className="mt-3 flex flex-col gap-2.5">
                    {draft.sections.map((section, sectionIndex) => {
                        const lines = draft.items.filter(
                            (item) => item.section === section.key,
                        );
                        const itemTotal = lines.reduce(
                            (sum, item) => sum + (item.weight || 0),
                            0,
                        );

                        return (
                            <div
                                key={section.key}
                                className="overflow-hidden rounded-xl border border-dashed border-sidebar-border bg-muted/20"
                            >
                                <div className="flex items-start gap-2 border-b border-dashed border-sidebar-border bg-muted/50 p-3">
                                    <div className="min-w-0 flex-1 space-y-2">
                                        <Input
                                            value={section.name}
                                            onChange={(event) =>
                                                patchSection(sectionIndex, {
                                                    name: event.target.value,
                                                })
                                            }
                                            placeholder="Section name, e.g. On the floor"
                                            aria-label={`Section ${sectionIndex + 1} name`}
                                            className="bg-background font-medium"
                                        />
                                        <Input
                                            value={section.description}
                                            onChange={(event) =>
                                                patchSection(sectionIndex, {
                                                    description:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="What this section is for (optional)"
                                            aria-label={`Section ${sectionIndex + 1} description`}
                                            className="bg-background"
                                        />
                                    </div>
                                    <div className="w-[5.5rem] shrink-0 space-y-1">
                                        <PercentInput
                                            value={section.weight}
                                            onChange={(weight) =>
                                                patchSection(sectionIndex, {
                                                    weight,
                                                })
                                            }
                                            label={`Section ${sectionIndex + 1} share of the appraisal`}
                                            className="bg-background"
                                        />
                                        <p className="text-center text-[10px] leading-tight text-muted-foreground">
                                            of the appraisal
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-9 shrink-0 text-muted-foreground hover:text-destructive"
                                        aria-label={`Remove section ${sectionIndex + 1}`}
                                        disabled={draft.sections.length <= 1}
                                        onClick={() =>
                                            removeSection(sectionIndex)
                                        }
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>

                                {lines.length === 0 ? (
                                    <p className="px-3 py-5 text-center text-xs text-muted-foreground">
                                        This section measures nothing yet.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-dashed divide-sidebar-border">
                                        {lines.map((item) => (
                                            <CriterionLine
                                                key={item.id}
                                                item={item}
                                                catalogue={catalogue}
                                                criteria={criteria}
                                                used={used}
                                                instruments={instrumentOptions}
                                                frameworkScale={draft.scale}
                                                share={shareOf(
                                                    section.weight,
                                                    sectionTotal,
                                                    item.weight,
                                                    itemTotal,
                                                )}
                                                error={
                                                    errors[
                                                        `items.${draft.items.indexOf(item)}.name`
                                                    ] ??
                                                    errors[
                                                        `items.${draft.items.indexOf(item)}.criterion`
                                                    ]
                                                }
                                                onPick={(choice) =>
                                                    onChange({
                                                        items: draft.items.map(
                                                            (row) =>
                                                                row.id ===
                                                                item.id
                                                                    ? lineFor(
                                                                          choice,
                                                                          row.section,
                                                                          row,
                                                                      )
                                                                    : row,
                                                        ),
                                                    })
                                                }
                                                onPatch={(patch) =>
                                                    patchItem(item.id, patch)
                                                }
                                                onRemove={() =>
                                                    onChange({
                                                        items: draft.items.filter(
                                                            (row) =>
                                                                row.id !==
                                                                item.id,
                                                        ),
                                                    })
                                                }
                                            />
                                        ))}
                                    </ul>
                                )}

                                <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-dashed border-sidebar-border p-3">
                                    <FormSelect
                                        value=""
                                        onChange={(choice) =>
                                            onChange({
                                                items: [
                                                    ...draft.items,
                                                    lineFor(
                                                        choice,
                                                        section.key,
                                                    ),
                                                ],
                                            })
                                        }
                                        placeholder="Add something to measure…"
                                        options={[
                                            ...criteria
                                                .filter(
                                                    (criterion) =>
                                                        !used.has(
                                                            criterion.key,
                                                        ),
                                                )
                                                .map((criterion) => ({
                                                    value: criterion.key,
                                                    label: criterion.name,
                                                })),
                                            {
                                                value: OWN,
                                                label: 'Write your own…',
                                            },
                                        ]}
                                        className="w-full bg-background sm:w-64"
                                    />
                                    <WeightTally
                                        total={itemTotal}
                                        empty={lines.length === 0}
                                        text={`${lines.length} ${lines.length === 1 ? 'criterion' : 'criteria'} · ${roundWeight(itemTotal)}% of this section`}
                                        onSplit={
                                            lines.length > 0
                                                ? () =>
                                                      onChange({
                                                          items: splitEvenly(
                                                              draft.items,
                                                              (row) =>
                                                                  row.section ===
                                                                  section.key,
                                                          ),
                                                      })
                                                : undefined
                                        }
                                    />
                                </div>
                            </div>
                        );
                    })}
                </div>

                <div className="mt-2.5 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-sidebar-border/70 bg-muted/40 px-3 py-2.5 dark:border-sidebar-border">
                    <p className="text-xs text-muted-foreground">
                        {draft.sections.length}{' '}
                        {draft.sections.length === 1 ? 'section' : 'sections'} ·{' '}
                        {draft.items.length}{' '}
                        {draft.items.length === 1 ? 'criterion' : 'criteria'} in
                        total
                    </p>
                    <WeightTally
                        total={sectionTotal}
                        empty={false}
                        text={`Sections total ${roundWeight(sectionTotal)}%`}
                        onSplit={
                            draft.sections.length > 0
                                ? () =>
                                      onChange({
                                          sections: splitEvenly(
                                              draft.sections,
                                              () => true,
                                          ),
                                      })
                                : undefined
                        }
                    />
                </div>

                <InputError message={errors.items} className="mt-2" />
                <InputError message={errors.sections} className="mt-2" />
            </div>

            {/* ── How the result is reported ─────────────────────────────── */}
            <div>
                <h2 className="text-sm font-semibold text-foreground">
                    How a result is reported
                </h2>
                <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                    {
                        RESULT_DISPLAYS.find(
                            (display) => display.value === draft.result_display,
                        )?.hint
                    }
                </p>

                <div className="mt-2.5 flex flex-wrap gap-2">
                    {RESULT_DISPLAYS.map((display) => (
                        <button
                            key={display.value}
                            type="button"
                            onClick={() =>
                                onChange({
                                    result_display:
                                        display.value as ResultDisplay,
                                })
                            }
                            aria-pressed={
                                draft.result_display === display.value
                            }
                            className={cn(
                                'min-h-9 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                draft.result_display === display.value
                                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 text-foreground'
                                    : 'border-sidebar-border/70 text-muted-foreground hover:text-foreground dark:border-sidebar-border',
                            )}
                        >
                            {display.label}
                        </button>
                    ))}
                </div>

                <div className="mt-3 rounded-xl border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border">
                    <RatingLadder bands={draft.bands} percent={null} />

                    <button
                        type="button"
                        onClick={() => setShowBands((open) => !open)}
                        aria-expanded={showBands}
                        className="mt-3 text-[11px] font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        {showBands
                            ? 'Done with the ratings'
                            : 'Put these ratings in your own words'}
                    </button>

                    {showBands && (
                        <ul className="mt-3 space-y-2">
                            {draft.bands.map((band, index) => (
                                <li
                                    key={band.key}
                                    className="grid gap-2 rounded-lg border border-dashed border-sidebar-border bg-muted/20 p-2 sm:grid-cols-[minmax(0,1fr)_5rem_9rem_auto]"
                                >
                                    <Input
                                        value={band.label}
                                        onChange={(event) =>
                                            patchBand(index, {
                                                label: event.target.value,
                                            })
                                        }
                                        placeholder="Label, e.g. Exceeds Expectations"
                                        aria-label={`Rating ${index + 1} label`}
                                        className="bg-background"
                                    />
                                    <Input
                                        type="number"
                                        min="0"
                                        max="100"
                                        value={band.min_percent}
                                        onChange={(event) =>
                                            patchBand(index, {
                                                min_percent: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                        aria-label={`Rating ${index + 1} starts at`}
                                        className="bg-background tabular-nums"
                                    />
                                    <FormSelect
                                        value={band.tone}
                                        onChange={(tone) =>
                                            patchBand(index, {
                                                tone: tone as BandTone,
                                            })
                                        }
                                        options={tones.map((tone) => ({
                                            value: tone,
                                            label:
                                                tone.charAt(0).toUpperCase() +
                                                tone.slice(1),
                                        }))}
                                        className="w-full bg-background"
                                    />
                                    <div className="flex items-center gap-1">
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'size-4 shrink-0 rounded-sm',
                                                bandTone(band.tone).fill,
                                            )}
                                        />

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            aria-label={`Remove rating ${index + 1}`}
                                            disabled={draft.bands.length <= 2}
                                            onClick={() =>
                                                onChange({
                                                    bands: draft.bands.filter(
                                                        (_, at) => at !== index,
                                                    ),
                                                })
                                            }
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </div>

                                    <Input
                                        value={band.description ?? ''}
                                        onChange={(event) =>
                                            patchBand(index, {
                                                description: event.target.value,
                                            })
                                        }
                                        placeholder="What this rating means (optional)"
                                        aria-label={`Rating ${index + 1} description`}
                                        className="bg-background sm:col-span-4"
                                    />
                                </li>
                            ))}

                            <li>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={draft.bands.length >= 8}
                                    onClick={() =>
                                        onChange({
                                            bands: [
                                                ...draft.bands,
                                                {
                                                    key: nextKey('band'),
                                                    label: '',
                                                    min_percent: 0,
                                                    description: null,
                                                    tone: 'neutral',
                                                },
                                            ],
                                        })
                                    }
                                >
                                    <Plus className="size-4" />
                                    Add a rating
                                </Button>
                            </li>
                        </ul>
                    )}
                </div>

                <InputError message={errors.bands} className="mt-2" />
            </div>
        </div>
    );
}

/** One criterion of one section: where it came from, how it is rated, what it carries. */
function CriterionLine({
    item,
    catalogue,
    criteria,
    used,
    instruments,
    frameworkScale,
    share,
    error,
    onPick,
    onPatch,
    onRemove,
}: {
    item: FrameworkItemDraft;
    catalogue: Map<string, CriterionBlueprint>;
    criteria: CriterionBlueprint[];
    used: Set<string>;
    instruments: SelectOption[];
    frameworkScale: string;
    share: number | null;
    error?: string;
    onPick: (choice: string) => void;
    onPatch: (patch: Partial<FrameworkItemDraft>) => void;
    onRemove: () => void;
}) {
    const own = item.criterion === null;
    const criterion = own ? null : catalogue.get(item.criterion ?? '');

    const choices: SelectOption[] = [
        ...criteria.map((option) => ({
            value: option.key,
            label:
                used.has(option.key) && option.key !== item.criterion
                    ? `${option.name} — already in this framework`
                    : option.name,
            disabled: used.has(option.key) && option.key !== item.criterion,
        })),
        { value: OWN, label: 'One you write yourself' },
    ];

    return (
        <li className="grid gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_5.5rem_2.25rem] sm:items-start">
            <div className="min-w-0 space-y-1.5">
                <FormSelect
                    value={own ? OWN : (item.criterion ?? OWN)}
                    onChange={onPick}
                    options={choices}
                    className="w-full bg-background"
                    aria-invalid={error ? true : undefined}
                />

                {own ? (
                    <div className="space-y-1.5 rounded-lg border border-sidebar-border/70 bg-background p-2.5 dark:border-sidebar-border">
                        <Input
                            value={item.name}
                            onChange={(event) =>
                                onPatch({ name: event.target.value })
                            }
                            placeholder="Name it, e.g. Shift handover"
                            aria-label="Your criterion's name"
                            aria-invalid={error ? true : undefined}
                        />
                        <Input
                            value={item.description}
                            onChange={(event) =>
                                onPatch({ description: event.target.value })
                            }
                            placeholder="What it means, for whoever is rating it (optional)"
                            aria-label="What your criterion means"
                        />
                        <FormSelect
                            value={item.scale || frameworkScale}
                            onChange={(scale) => onPatch({ scale })}
                            options={instruments}
                        />
                        <p className="text-[11px] leading-relaxed text-muted-foreground">
                            This joins your criteria catalogue, so any framework
                            you build later can measure it too.
                        </p>
                    </div>
                ) : (
                    <p className="text-xs leading-relaxed text-muted-foreground">
                        {criterion?.description}
                        <span className="px-1.5 text-muted-foreground/40">
                            ·
                        </span>
                        <span className="text-muted-foreground/70">
                            {criterion?.scale}
                        </span>
                    </p>
                )}

                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>

            <div className="space-y-1">
                <PercentInput
                    value={item.weight}
                    onChange={(weight) => onPatch({ weight })}
                    label={`${item.name || 'This criterion'} share of its section`}
                    className="bg-background"
                />
                <p className="text-center text-[10px] leading-tight text-muted-foreground">
                    {share === null ? '—' : `${roundWeight(share)}% overall`}
                </p>
            </div>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-9 justify-self-end text-muted-foreground hover:text-destructive"
                aria-label={`Remove ${item.name || 'this criterion'}`}
                onClick={onRemove}
            >
                <X className="size-4" />
            </Button>
        </li>
    );
}

import { Plus, X } from 'lucide-react';
import { useMemo } from 'react';
import type { SelectOption } from '@/components/form-select';
import { FormSelect } from '@/components/form-select';
import { ModalSection } from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { KpiCriterion, RatingScaleOption } from '../types';
import {
    PercentInput,
    roundWeight,
    shareOf,
    splitEvenly,
    WeightTally,
} from './weight-controls';

/** One weighted section of the framework being edited. */
export type SectionDraft = {
    key: string;
    name: string;
    description: string;
    weight: number;
};

/**
 * One line inside a section. `kpi_criterion_id` is the whole story of where the
 * line comes from: an id means it draws from the criteria catalogue and takes
 * its wording from there, so every framework asks the question the same way; null
 * means it was written straight into this framework and exists nowhere else.
 */
export type ItemDraft = {
    kpi_criterion_id: number | null;
    rating_scale_id: number | null;
    section_key: string;
    name: string;
    description: string;
    weight: number;
};

/** The picker value standing for a line that is not a catalogue criterion. */
const ONE_OFF = 'one_off';

/** The scale value standing for "whatever this line would inherit". */
const INHERIT = 'inherit';

type Props = {
    sections: SectionDraft[];
    items: ItemDraft[];
    criteria: KpiCriterion[];
    scales: RatingScaleOption[];
    /** The framework's own default scale, as the form holds it. */
    frameworkScaleId: string;
    errors: Record<string, string>;
    onChange: (patch: {
        sections?: SectionDraft[];
        items?: ItemDraft[];
    }) => void;
};

/**
 * The half of the framework editor that decides **what an appraisal asks**:
 * weighted sections, and the criteria inside them.
 *
 * A criterion is chosen, not typed. Picking one from the catalogue links the
 * line to it, and the line then shows the catalogue's wording, meaning and
 * scale — so retitling a criterion once reaches every framework drawing on it.
 * Writing a one-off is still possible and still legitimate, but it is now an
 * explicit choice with its own field and its own warning, rather than what you
 * get by overtyping a name.
 *
 * Weights are relative twice over — a section's share of the appraisal, and a
 * line's share of its section — which is the part people misread, so each line
 * also states what it ends up worth overall.
 */
export function MeasurementEditor({
    sections,
    items,
    criteria,
    scales,
    frameworkScaleId,
    errors,
    onChange,
}: Props) {
    const byId = useMemo(
        () => new Map(criteria.map((criterion) => [criterion.id, criterion])),
        [criteria],
    );

    const scaleFor = (id: number | null | undefined) =>
        scales.find((scale) => scale.id === id) ?? null;

    const frameworkScale = scaleFor(Number(frameworkScaleId) || null);

    const sectionTotal = sections.reduce(
        (sum, section) => sum + (section.weight || 0),
        0,
    );

    // A criterion belongs to one line of a framework: asking the same question
    // twice only splits its weight in two.
    const usedIds = new Set(
        items
            .map((item) => item.kpi_criterion_id)
            .filter((id): id is number => id !== null),
    );

    const patchSection = (index: number, patch: Partial<SectionDraft>) =>
        onChange({
            sections: sections.map((section, i) =>
                i === index ? { ...section, ...patch } : section,
            ),
        });

    const patchItem = (index: number, patch: Partial<ItemDraft>) =>
        onChange({
            items: items.map((item, i) =>
                i === index ? { ...item, ...patch } : item,
            ),
        });

    const addSection = () =>
        onChange({
            sections: [
                ...sections,
                {
                    key: `section_${Date.now()}`,
                    name: '',
                    description: '',
                    weight: 0,
                },
            ],
        });

    const removeSection = (index: number) => {
        const removed = sections[index];

        onChange({
            sections: sections.filter((_, i) => i !== index),
            // Lines would otherwise point at a section that no longer exists.
            items: items.filter((item) => item.section_key !== removed.key),
        });
    };

    /**
     * A line built from a picker choice. A catalogue line borrows the criterion's
     * wording and leaves its scale unset, so it follows the catalogue; a weight
     * the editor already carries is kept, because it was set against the other
     * lines in the section rather than against the criterion.
     */
    const lineFor = (
        choice: string,
        sectionKey: string,
        current?: ItemDraft,
    ): ItemDraft => {
        const criterion =
            choice === ONE_OFF ? null : (byId.get(Number(choice)) ?? null);

        return {
            kpi_criterion_id: criterion?.id ?? null,
            rating_scale_id: null,
            section_key: sectionKey,
            name: criterion?.name ?? '',
            description: criterion?.description ?? '',
            weight: current?.weight || criterion?.weight || 0,
        };
    };

    const addItem = (sectionKey: string, choice: string) =>
        onChange({ items: [...items, lineFor(choice, sectionKey)] });

    const replaceItem = (index: number, choice: string) =>
        onChange({
            items: items.map((item, i) =>
                i === index ? lineFor(choice, item.section_key, item) : item,
            ),
        });

    const removeItem = (index: number) =>
        onChange({ items: items.filter((_, i) => i !== index) });

    const splitSections = () =>
        onChange({ sections: splitEvenly(sections, () => true) });

    const splitItems = (sectionKey: string) =>
        onChange({
            items: splitEvenly(
                items,
                (item) => item.section_key === sectionKey,
            ),
        });

    /** What a criterion is called, preferring the catalogue's current wording. */
    const wordingFor = (item: ItemDraft) => {
        const criterion =
            item.kpi_criterion_id === null
                ? null
                : byId.get(item.kpi_criterion_id);

        return {
            name: criterion?.name ?? item.name,
            description: criterion?.description ?? item.description,
            /** Null once a criterion has been archived out of the catalogue. */
            criterion: criterion ?? null,
        };
    };

    /** The choices for a line that already exists, its own included. */
    const choicesFor = (item: ItemDraft): SelectOption[] => {
        const options: SelectOption[] = criteria
            .filter(
                (criterion) =>
                    criterion.is_active ||
                    criterion.id === item.kpi_criterion_id,
            )
            .map((criterion) => {
                const taken =
                    usedIds.has(criterion.id) &&
                    criterion.id !== item.kpi_criterion_id;

                return {
                    value: String(criterion.id),
                    label: taken
                        ? `${criterion.name} — already in this framework`
                        : criterion.name,
                    disabled: taken,
                };
            });

        if (
            item.kpi_criterion_id !== null &&
            !byId.has(item.kpi_criterion_id)
        ) {
            options.unshift({
                value: String(item.kpi_criterion_id),
                label: `${item.name || 'This criterion'} — archived from the catalogue`,
            });
        }

        return [
            ...options,
            { value: ONE_OFF, label: 'A one-off, written here only' },
        ];
    };

    /** The choices for adding a line: what this framework does not ask yet. */
    const additions: SelectOption[] = [
        ...criteria
            .filter(
                (criterion) =>
                    criterion.is_active && !usedIds.has(criterion.id),
            )
            .map((criterion) => ({
                value: String(criterion.id),
                label: criterion.name,
            })),
        { value: ONE_OFF, label: 'Write a one-off criterion…' },
    ];

    return (
        <ModalSection
            title="What it measures"
            hint="Sections divide the appraisal between them; the criteria inside a section divide the section. Criteria come from the catalogue so the whole company asks a question the same way."
            action={
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addSection}
                    disabled={sections.length >= 10}
                >
                    <Plus className="size-4" />
                    Section
                </Button>
            }
        >
            {sections.map((section, sectionIndex) => {
                const lines = items
                    .map((item, index) => ({ item, index }))
                    .filter(({ item }) => item.section_key === section.key);
                const itemTotal = lines.reduce(
                    (sum, { item }) => sum + (item.weight || 0),
                    0,
                );

                return (
                    <div
                        key={section.key}
                        className="overflow-hidden rounded-xl border border-border"
                    >
                        {/* ── The section itself ───────────────────────── */}
                        <div className="flex items-start gap-2 border-b border-border bg-muted/40 p-3">
                            <div className="min-w-0 flex-1 space-y-2">
                                <Input
                                    value={section.name}
                                    onChange={(event) =>
                                        patchSection(sectionIndex, {
                                            name: event.target.value,
                                        })
                                    }
                                    placeholder="Section name, e.g. Goals & delivery"
                                    aria-label={`Section ${sectionIndex + 1} name`}
                                    className="bg-background font-medium"
                                />
                                <Input
                                    value={section.description}
                                    onChange={(event) =>
                                        patchSection(sectionIndex, {
                                            description: event.target.value,
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
                                        patchSection(sectionIndex, { weight })
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
                                disabled={sections.length <= 1}
                                onClick={() => removeSection(sectionIndex)}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>

                        {/* ── The criteria inside it ───────────────────── */}
                        {lines.length === 0 ? (
                            <p className="px-3 py-6 text-center text-xs text-muted-foreground">
                                This section measures nothing yet.
                            </p>
                        ) : (
                            <>
                                <div className="hidden gap-2 px-3 pt-3 pb-1 text-[11px] font-medium text-muted-foreground sm:grid sm:grid-cols-[minmax(0,1fr)_12rem_5.5rem_2.25rem]">
                                    <span>Criterion</span>
                                    <span>Measured on</span>
                                    <span>Of the section</span>
                                    <span />
                                </div>

                                <ul className="divide-y divide-border">
                                    {lines.map(({ item, index }) => (
                                        <CriterionLine
                                            key={index}
                                            item={item}
                                            wording={wordingFor(item)}
                                            choices={choicesFor(item)}
                                            scales={scales}
                                            inherited={
                                                scaleFor(
                                                    wordingFor(item).criterion
                                                        ?.rating_scale_id,
                                                ) ?? frameworkScale
                                            }
                                            fromCatalogue={
                                                scaleFor(
                                                    wordingFor(item).criterion
                                                        ?.rating_scale_id,
                                                ) !== null
                                            }
                                            share={shareOf(
                                                section.weight,
                                                sectionTotal,
                                                item.weight,
                                                itemTotal,
                                            )}
                                            error={
                                                errors[`items.${index}.name`] ??
                                                errors[
                                                    `items.${index}.kpi_criterion_id`
                                                ]
                                            }
                                            onPick={(choice) =>
                                                replaceItem(index, choice)
                                            }
                                            onPatch={(patch) =>
                                                patchItem(index, patch)
                                            }
                                            onRemove={() => removeItem(index)}
                                        />
                                    ))}
                                </ul>
                            </>
                        )}

                        {/* ── Adding to it ─────────────────────────────── */}
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-border p-3">
                            <FormSelect
                                value=""
                                onChange={(value) =>
                                    addItem(section.key, value)
                                }
                                placeholder="Add a criterion…"
                                options={additions}
                                className="w-full sm:w-64"
                            />
                            <WeightTally
                                total={itemTotal}
                                empty={lines.length === 0}
                                text={`${lines.length} ${lines.length === 1 ? 'criterion' : 'criteria'} · ${roundWeight(itemTotal)}% of this section`}
                                onSplit={
                                    lines.length > 0
                                        ? () => splitItems(section.key)
                                        : undefined
                                }
                            />
                        </div>
                    </div>
                );
            })}

            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                <p className="text-xs text-muted-foreground">
                    {sections.length}{' '}
                    {sections.length === 1 ? 'section' : 'sections'} ·{' '}
                    {items.length}{' '}
                    {items.length === 1 ? 'criterion' : 'criteria'} in total
                </p>
                <WeightTally
                    total={sectionTotal}
                    empty={false}
                    text={`Sections total ${roundWeight(sectionTotal)}%`}
                    onSplit={sections.length > 0 ? splitSections : undefined}
                />
            </div>

            {errors.items && (
                <p className="text-sm text-destructive">{errors.items}</p>
            )}
            {errors.sections && (
                <p className="text-sm text-destructive">{errors.sections}</p>
            )}
        </ModalSection>
    );
}

/** One criterion of one section: where it came from, how it is rated, what it carries. */
function CriterionLine({
    item,
    wording,
    choices,
    scales,
    inherited,
    fromCatalogue,
    share,
    error,
    onPick,
    onPatch,
    onRemove,
}: {
    item: ItemDraft;
    wording: {
        name: string;
        description: string;
        criterion: KpiCriterion | null;
    };
    choices: SelectOption[];
    scales: RatingScaleOption[];
    /** The scale this line falls back to when it names none of its own. */
    inherited: RatingScaleOption | null;
    /** Whether that fallback is the criterion's, rather than the framework's. */
    fromCatalogue: boolean;
    share: number | null;
    error?: string;
    onPick: (choice: string) => void;
    onPatch: (patch: Partial<ItemDraft>) => void;
    onRemove: () => void;
}) {
    const oneOff = item.kpi_criterion_id === null;
    const measuredOn =
        item.rating_scale_id === null
            ? inherited
            : (scales.find((scale) => scale.id === item.rating_scale_id) ??
              null);

    return (
        <li className="grid gap-2 px-3 py-2.5 sm:grid-cols-[minmax(0,1fr)_12rem_5.5rem_2.25rem] sm:items-start">
            <div className="min-w-0 space-y-1.5">
                <FormSelect
                    value={oneOff ? ONE_OFF : String(item.kpi_criterion_id)}
                    onChange={onPick}
                    options={choices}
                    className="w-full"
                    aria-invalid={error ? true : undefined}
                />

                {oneOff ? (
                    <div className="space-y-1.5 rounded-lg border border-dashed border-border bg-muted/20 p-2.5">
                        <Input
                            value={item.name}
                            onChange={(event) =>
                                onPatch({ name: event.target.value })
                            }
                            placeholder="Name it, e.g. Shift handover quality"
                            aria-label="One-off criterion name"
                            aria-invalid={error ? true : undefined}
                        />
                        <Input
                            value={item.description}
                            onChange={(event) =>
                                onPatch({ description: event.target.value })
                            }
                            placeholder="What it means, for the evaluator (optional)"
                            aria-label="One-off criterion meaning"
                        />
                        <p className="text-[11px] leading-relaxed text-muted-foreground">
                            Only this framework asks it. It stays out of the
                            catalogue, so nothing else can reuse it and it will
                            not be reported on across frameworks.
                        </p>
                    </div>
                ) : (
                    wording.description && (
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            {wording.description}
                        </p>
                    )
                )}

                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>

            <div className="space-y-1">
                <FormSelect
                    value={
                        item.rating_scale_id === null
                            ? INHERIT
                            : String(item.rating_scale_id)
                    }
                    onChange={(value) =>
                        onPatch({
                            rating_scale_id:
                                value === INHERIT ? null : Number(value),
                        })
                    }
                    options={[
                        {
                            value: INHERIT,
                            label: fromCatalogue
                                ? 'Same as catalogue'
                                : 'Framework default',
                        },
                        ...scales.map((scale) => ({
                            value: String(scale.id),
                            label: scale.name,
                        })),
                    ]}
                />
                {measuredOn && (
                    <p className="truncate text-[11px] leading-tight text-muted-foreground">
                        {item.rating_scale_id === null
                            ? `${measuredOn.name} · ${measuredOn.descriptor}`
                            : measuredOn.descriptor}
                    </p>
                )}
            </div>

            <div className="space-y-1">
                <PercentInput
                    value={item.weight}
                    onChange={(weight) => onPatch({ weight })}
                    label={`${wording.name || 'Criterion'} share of its section`}
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
                aria-label={`Remove ${wording.name || 'this criterion'}`}
                onClick={onRemove}
            >
                <X className="size-4" />
            </Button>
        </li>
    );
}

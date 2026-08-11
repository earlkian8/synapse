import { useForm } from '@inertiajs/react';
import { GripVertical, Layers, Plus, X } from 'lucide-react';
import { useMemo } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { RatingLadder } from '@/features/performance/components/rating-ladder';
import { bandTone } from '@/features/performance/constants';
import type {
    BandTone,
    RatingBand,
    ResultDisplay,
    ReviewTemplateOption,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';
import { kpiConfigRoutes } from '../routes';
import type {
    AudienceOptions,
    KpiCriterion,
    RatingScaleOption,
} from '../types';

const RESULT_DISPLAYS: { value: ResultDisplay; label: string; hint: string }[] =
    [
        {
            value: 'band',
            label: 'The rating',
            hint: 'The scorecard leads with the band — "Exceeds Expectations".',
        },
        {
            value: 'percent',
            label: 'Attainment',
            hint: 'The scorecard leads with the number — "78.4%".',
        },
        {
            value: 'points',
            label: 'Points out of 5',
            hint: 'The scorecard leads with the 1–5 index.',
        },
    ];

const AUDIENCES: {
    value: ReviewTemplateOption['applies_to'];
    label: string;
}[] = [
    { value: 'all', label: 'Everyone' },
    { value: 'department', label: 'Departments' },
    { value: 'position', label: 'Positions' },
    { value: 'employment_type', label: 'Employment types' },
];

type SectionDraft = {
    key: string;
    name: string;
    description: string;
    weight: number;
};

type ItemDraft = {
    kpi_criterion_id: number | null;
    rating_scale_id: number | null;
    section_key: string;
    name: string;
    description: string;
    weight: number;
};

type Props = {
    template: ReviewTemplateOption | null;
    scales: RatingScaleOption[];
    criteria: KpiCriterion[];
    audiences: AudienceOptions;
    tones: BandTone[];
    defaultBands: RatingBand[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * The appraisal-framework editor — the surface that makes performance
 * configurable rather than assumed.
 *
 * Three decisions, in the order they matter: **who** this framework reviews,
 * **what** it measures (weighted sections, and the criteria inside them, each on
 * its own scale), and **how the result is reported** — the rating model, drawn
 * live as the ladder the scorecard will show. Nothing here is a preference: each
 * choice changes what an appraisal produces.
 */
export function FrameworkModal({
    template,
    scales,
    criteria,
    audiences,
    tones,
    defaultBands,
    open,
    onOpenChange,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="2xl">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Layers />
                        </ModalIcon>
                    }
                    title={
                        template ? 'Edit framework' : 'New appraisal framework'
                    }
                    description="Who it reviews, what it measures, and the words the result is reported in."
                />
                {open && (
                    <FormBody
                        key={template?.id ?? 'new'}
                        template={template}
                        scales={scales}
                        criteria={criteria}
                        audiences={audiences}
                        tones={tones}
                        defaultBands={defaultBands}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    template,
    scales,
    criteria,
    audiences,
    tones,
    defaultBands,
    onDone,
}: Omit<Props, 'open' | 'onOpenChange'> & { onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        name: template?.name ?? '',
        description: template?.description ?? '',
        rating_scale_id: String(
            template?.rating_scale_id ??
                scales.find((scale) => scale.is_default)?.id ??
                scales[0]?.id ??
                '',
        ),
        result_display: (template?.result_display ?? 'band') as ResultDisplay,
        applies_to: template?.applies_to ?? 'all',
        applies_to_values: template?.applies_to_values ?? [],
        is_default: template?.is_default ?? false,
        is_active: template?.is_active ?? true,
        sections: (
            template?.sections ?? [
                {
                    key: 'overall',
                    name: 'Performance criteria',
                    description: '',
                    weight: 100,
                },
            ]
        ).map(
            (section): SectionDraft => ({
                key: section.key,
                name: section.name,
                description: section.description ?? '',
                weight: section.weight,
            }),
        ),
        bands: (template?.bands ?? defaultBands).map(
            (band): RatingBand => ({ ...band }),
        ),
        items: (template?.items ?? []).map(
            (item): ItemDraft => ({
                kpi_criterion_id: item.kpi_criterion_id,
                rating_scale_id: item.rating_scale_id,
                section_key: item.section_key,
                name: item.name,
                description: item.description ?? '',
                weight: item.weight,
            }),
        ),
    });

    const scaleOptions = useMemo(
        () => [
            { value: 'inherit', label: 'Framework default' },
            ...scales.map((scale) => ({
                value: String(scale.id),
                label: `${scale.name} · ${scale.descriptor}`,
            })),
        ],
        [scales],
    );

    const sectionTotal = data.sections.reduce(
        (sum, section) => sum + (section.weight || 0),
        0,
    );

    const patchSection = (index: number, patch: Partial<SectionDraft>) =>
        setData(
            'sections',
            data.sections.map((section, i) =>
                i === index ? { ...section, ...patch } : section,
            ),
        );

    const patchItem = (index: number, patch: Partial<ItemDraft>) =>
        setData(
            'items',
            data.items.map((item, i) =>
                i === index ? { ...item, ...patch } : item,
            ),
        );

    const patchBand = (index: number, patch: Partial<RatingBand>) =>
        setData(
            'bands',
            data.bands.map((band, i) =>
                i === index ? { ...band, ...patch } : band,
            ),
        );

    const addSection = () => {
        const key = `section_${Date.now()}`;

        setData('sections', [
            ...data.sections,
            { key, name: '', description: '', weight: 0 },
        ]);
    };

    const removeSection = (index: number) => {
        const removed = data.sections[index];

        setData((current) => ({
            ...current,
            sections: current.sections.filter((_, i) => i !== index),
            // Items would otherwise point at a section that no longer exists.
            items: current.items.filter(
                (item) => item.section_key !== removed.key,
            ),
        }));
    };

    const addItem = (sectionKey: string, criterionId: string) => {
        const criterion = criteria.find(
            (candidate) => String(candidate.id) === criterionId,
        );

        setData('items', [
            ...data.items,
            {
                kpi_criterion_id: criterion?.id ?? null,
                rating_scale_id: criterion?.rating_scale_id ?? null,
                section_key: sectionKey,
                name: criterion?.name ?? '',
                description: criterion?.description ?? '',
                weight: criterion?.weight ?? 0,
            },
        ]);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const opts = { preserveScroll: true, onSuccess: onDone };

        if (template) {
            post(kpiConfigRoutes.frameworks.update(template.hashid), opts);
        } else {
            post(kpiConfigRoutes.frameworks.store, opts);
        }
    };

    const messages = errors as Record<string, string>;

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-7">
                {/* ── Identity ───────────────────────────────────────────── */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField label="Name" required error={errors.name}>
                        <Input
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder="e.g. Individual Contributor Review"
                            required
                        />
                    </FormField>

                    <FormField
                        label="Default rating scale"
                        hint="Used by any criterion that names none of its own."
                        error={errors.rating_scale_id}
                    >
                        <FormSelect
                            value={data.rating_scale_id}
                            onChange={(value) =>
                                setData('rating_scale_id', value)
                            }
                            options={scales.map((scale) => ({
                                value: String(scale.id),
                                label: `${scale.name} · ${scale.descriptor}`,
                            }))}
                        />
                    </FormField>
                </div>

                <FormField label="Description" error={errors.description}>
                    <Input
                        value={data.description ?? ''}
                        onChange={(event) =>
                            setData('description', event.target.value)
                        }
                        placeholder="What this framework is for, and who it is written for."
                    />
                </FormField>

                {/* ── Who it reviews ─────────────────────────────────────── */}
                <ModalSection
                    title="Who it reviews"
                    hint="When someone matches more than one framework, the narrowest rule wins."
                >
                    <div className="flex flex-wrap gap-2">
                        {AUDIENCES.map((audience) => (
                            <button
                                key={audience.value}
                                type="button"
                                onClick={() => {
                                    setData((current) => ({
                                        ...current,
                                        applies_to: audience.value,
                                        applies_to_values: [],
                                    }));
                                }}
                                aria-pressed={
                                    data.applies_to === audience.value
                                }
                                className={cn(
                                    'min-h-9 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                    data.applies_to === audience.value
                                        ? 'border-[#0ABFBF] bg-[#0ABFBF]/10'
                                        : 'border-border text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {audience.label}
                            </button>
                        ))}
                    </div>

                    {data.applies_to !== 'all' && (
                        <ul className="grid max-h-44 gap-1 overflow-y-auto rounded-lg border border-border p-2 sm:grid-cols-2">
                            {audiences[data.applies_to].map((option) => (
                                <li
                                    key={option.value}
                                    className="flex items-center gap-2.5 px-1.5 py-1"
                                >
                                    <Checkbox
                                        id={`audience-${option.value}`}
                                        checked={data.applies_to_values.includes(
                                            option.value,
                                        )}
                                        onCheckedChange={(checked) =>
                                            setData(
                                                'applies_to_values',
                                                checked === true
                                                    ? [
                                                          ...data.applies_to_values,
                                                          option.value,
                                                      ]
                                                    : data.applies_to_values.filter(
                                                          (value) =>
                                                              value !==
                                                              option.value,
                                                      ),
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={`audience-${option.value}`}
                                        className="min-w-0 cursor-pointer truncate text-sm font-normal"
                                    >
                                        {option.label}
                                    </Label>
                                </li>
                            ))}
                        </ul>
                    )}
                    {messages.applies_to_values && (
                        <p className="text-sm text-destructive">
                            {messages.applies_to_values}
                        </p>
                    )}
                </ModalSection>

                {/* ── What it measures ───────────────────────────────────── */}
                <ModalSection
                    title="What it measures"
                    hint="Sections are weighted against each other; the criteria inside one are weighted against each other."
                    action={
                        <div className="flex items-center gap-2">
                            <span
                                className={cn(
                                    'text-xs font-medium tabular-nums',
                                    Math.round(sectionTotal) === 100
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-amber-600 dark:text-amber-400',
                                )}
                            >
                                {sectionTotal}%
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addSection}
                                disabled={data.sections.length >= 10}
                            >
                                <Plus className="size-4" />
                                Section
                            </Button>
                        </div>
                    }
                >
                    {data.sections.map((section, sectionIndex) => {
                        const sectionItems = data.items
                            .map((item, index) => ({ item, index }))
                            .filter(
                                ({ item }) => item.section_key === section.key,
                            );
                        const itemTotal = sectionItems.reduce(
                            (sum, { item }) => sum + (item.weight || 0),
                            0,
                        );

                        return (
                            <div
                                key={section.key}
                                className="rounded-lg border border-border"
                            >
                                <div className="flex items-start gap-2 border-b border-border bg-muted/30 p-3">
                                    <GripVertical
                                        className="mt-2 size-4 shrink-0 text-muted-foreground/50"
                                        aria-hidden="true"
                                    />
                                    <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-[minmax(0,1fr)_6rem]">
                                        <Input
                                            value={section.name}
                                            onChange={(event) =>
                                                patchSection(sectionIndex, {
                                                    name: event.target.value,
                                                })
                                            }
                                            placeholder="Section name, e.g. Goals & delivery"
                                            aria-label={`Section ${sectionIndex + 1} name`}
                                        />
                                        <Input
                                            type="number"
                                            min="0"
                                            max="100"
                                            value={section.weight}
                                            onChange={(event) =>
                                                patchSection(sectionIndex, {
                                                    weight: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                            aria-label={`Section ${sectionIndex + 1} weight`}
                                            className="tabular-nums"
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
                                            className="sm:col-span-2"
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                        aria-label={`Remove section ${sectionIndex + 1}`}
                                        disabled={data.sections.length <= 1}
                                        onClick={() =>
                                            removeSection(sectionIndex)
                                        }
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>

                                <ul className="divide-y divide-border">
                                    {sectionItems.map(({ item, index }) => (
                                        <li
                                            key={index}
                                            className="grid gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_10rem_5rem_auto]"
                                        >
                                            <Input
                                                value={item.name}
                                                onChange={(event) =>
                                                    patchItem(index, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                                placeholder="What is measured"
                                                aria-label="Criterion name"
                                            />
                                            <FormSelect
                                                value={
                                                    item.rating_scale_id ===
                                                    null
                                                        ? 'inherit'
                                                        : String(
                                                              item.rating_scale_id,
                                                          )
                                                }
                                                onChange={(value) =>
                                                    patchItem(index, {
                                                        rating_scale_id:
                                                            value === 'inherit'
                                                                ? null
                                                                : Number(value),
                                                    })
                                                }
                                                options={scaleOptions}
                                            />
                                            <Input
                                                type="number"
                                                min="0"
                                                max="100"
                                                value={item.weight}
                                                onChange={(event) =>
                                                    patchItem(index, {
                                                        weight: Number(
                                                            event.target.value,
                                                        ),
                                                    })
                                                }
                                                aria-label="Criterion weight"
                                                className="tabular-nums"
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8 text-muted-foreground hover:text-destructive"
                                                aria-label={`Remove ${item.name || 'criterion'}`}
                                                onClick={() =>
                                                    setData(
                                                        'items',
                                                        data.items.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <X className="size-4" />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>

                                <div className="flex flex-wrap items-center gap-2 border-t border-border p-3">
                                    <FormSelect
                                        value=""
                                        onChange={(value) =>
                                            addItem(section.key, value)
                                        }
                                        placeholder="Add a criterion…"
                                        options={[
                                            ...criteria
                                                .filter(
                                                    (criterion) =>
                                                        criterion.is_active,
                                                )
                                                .map((criterion) => ({
                                                    value: String(criterion.id),
                                                    label: criterion.name,
                                                })),
                                            {
                                                value: 'blank',
                                                label: 'Something not in the catalogue…',
                                            },
                                        ]}
                                        className="w-full sm:w-72"
                                    />
                                    <span
                                        className={cn(
                                            'text-xs tabular-nums',
                                            sectionItems.length === 0
                                                ? 'text-muted-foreground'
                                                : Math.round(itemTotal) === 100
                                                  ? 'text-emerald-600 dark:text-emerald-400'
                                                  : 'text-amber-600 dark:text-amber-400',
                                        )}
                                    >
                                        {sectionItems.length} criteria ·{' '}
                                        {itemTotal}% within the section
                                    </span>
                                </div>
                            </div>
                        );
                    })}

                    {messages.items && (
                        <p className="text-sm text-destructive">
                            {messages.items}
                        </p>
                    )}
                    {messages.sections && (
                        <p className="text-sm text-destructive">
                            {messages.sections}
                        </p>
                    )}
                </ModalSection>

                {/* ── The rating model ───────────────────────────────────── */}
                <ModalSection
                    title="How the result is reported"
                    hint="The bands are your company's words for a result. The lowest has to start at 0%, so nothing comes back unrated."
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setData('bands', [
                                    ...data.bands,
                                    {
                                        key: `band_${Date.now()}`,
                                        label: '',
                                        min_percent: 0,
                                        description: null,
                                        tone: 'neutral',
                                    },
                                ])
                            }
                            disabled={data.bands.length >= 8}
                        >
                            <Plus className="size-4" />
                            Band
                        </Button>
                    }
                >
                    <div className="rounded-lg border border-border p-3">
                        <RatingLadder bands={data.bands} percent={null} />
                    </div>

                    <ul className="space-y-2">
                        {data.bands.map((band, index) => (
                            <li
                                key={band.key}
                                className="grid gap-2 rounded-lg border border-border p-2 sm:grid-cols-[minmax(0,1fr)_5rem_9rem_auto]"
                            >
                                <Input
                                    value={band.label}
                                    onChange={(event) =>
                                        patchBand(index, {
                                            label: event.target.value,
                                        })
                                    }
                                    placeholder="Label, e.g. Exceeds Expectations"
                                    aria-label={`Band ${index + 1} label`}
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
                                    aria-label={`Band ${index + 1} starts at`}
                                    className="tabular-nums"
                                />
                                <FormSelect
                                    value={band.tone}
                                    onChange={(value) =>
                                        patchBand(index, {
                                            tone: value as BandTone,
                                        })
                                    }
                                    options={tones.map((tone) => ({
                                        value: tone,
                                        label:
                                            tone.charAt(0).toUpperCase() +
                                            tone.slice(1),
                                    }))}
                                />
                                <div className="flex items-center gap-1">
                                    <span
                                        className={cn(
                                            'size-4 shrink-0 rounded-sm',
                                            bandTone(band.tone).fill,
                                        )}
                                        aria-hidden="true"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 text-muted-foreground hover:text-destructive"
                                        aria-label={`Remove band ${index + 1}`}
                                        disabled={data.bands.length <= 2}
                                        onClick={() =>
                                            setData(
                                                'bands',
                                                data.bands.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
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
                                    aria-label={`Band ${index + 1} description`}
                                    className="sm:col-span-4"
                                />
                            </li>
                        ))}
                    </ul>
                    {messages.bands && (
                        <p className="text-sm text-destructive">
                            {messages.bands}
                        </p>
                    )}

                    <FormField
                        label="The scorecard leads with"
                        group
                        hint={
                            RESULT_DISPLAYS.find(
                                (display) =>
                                    display.value === data.result_display,
                            )?.hint
                        }
                    >
                        <div className="flex flex-wrap gap-2">
                            {RESULT_DISPLAYS.map((display) => (
                                <button
                                    key={display.value}
                                    type="button"
                                    onClick={() =>
                                        setData('result_display', display.value)
                                    }
                                    aria-pressed={
                                        data.result_display === display.value
                                    }
                                    className={cn(
                                        'min-h-9 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                        data.result_display === display.value
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/10'
                                            : 'border-border text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {display.label}
                                </button>
                            ))}
                        </div>
                    </FormField>
                </ModalSection>

                <div className="space-y-2">
                    <div className="flex items-center gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                        <Checkbox
                            id="framework-default"
                            checked={data.is_default}
                            onCheckedChange={(checked) =>
                                setData('is_default', checked === true)
                            }
                        />
                        <Label
                            htmlFor="framework-default"
                            className="cursor-pointer text-sm font-normal"
                        >
                            Use this framework when nothing more specific
                            matches
                        </Label>
                    </div>
                    <div className="flex items-center gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                        <Checkbox
                            id="framework-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked === true)
                            }
                        />
                        <Label
                            htmlFor="framework-active"
                            className="cursor-pointer text-sm font-normal"
                        >
                            Offer this framework for new appraisals
                        </Label>
                    </div>
                </div>
            </ModalBody>

            <ModalFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {template ? 'Save framework' : 'Create framework'}
                </Button>
            </ModalFooter>
        </form>
    );
}

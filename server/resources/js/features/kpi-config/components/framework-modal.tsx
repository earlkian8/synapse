import { useForm } from '@inertiajs/react';
import { Layers, Plus, X } from 'lucide-react';
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
import { RESULT_DISPLAYS } from '../constants';
import { kpiConfigRoutes } from '../routes';
import type {
    AudienceOptions,
    KpiCriterion,
    RatingScaleOption,
} from '../types';
import type { ItemDraft, SectionDraft } from './measurement-editor';
import { MeasurementEditor } from './measurement-editor';

const AUDIENCES: {
    value: ReviewTemplateOption['applies_to'];
    label: string;
}[] = [
    { value: 'all', label: 'Everyone' },
    { value: 'department', label: 'Departments' },
    { value: 'position', label: 'Positions' },
    { value: 'employment_type', label: 'Employment types' },
];

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
        ).map((section): SectionDraft => ({
            key: section.key,
            name: section.name,
            description: section.description ?? '',
            weight: section.weight,
        })),
        bands: (template?.bands ?? defaultBands).map((band): RatingBand => ({
            ...band,
        })),
        items: (template?.items ?? []).map((item): ItemDraft => ({
            kpi_criterion_id: item.kpi_criterion_id,
            rating_scale_id: item.rating_scale_id,
            section_key: item.section_key,
            name: item.name,
            description: item.description ?? '',
            weight: item.weight,
        })),
    });

    const patchBand = (index: number, patch: Partial<RatingBand>) =>
        setData(
            'bands',
            data.bands.map((band, i) =>
                i === index ? { ...band, ...patch } : band,
            ),
        );

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
                <MeasurementEditor
                    sections={data.sections}
                    items={data.items}
                    criteria={criteria}
                    scales={scales}
                    frameworkScaleId={data.rating_scale_id}
                    errors={messages}
                    onChange={(patch) =>
                        setData((current) => ({ ...current, ...patch }))
                    }
                />

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

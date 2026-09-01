import { useForm } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    Plus,
    Sparkles,
    Trash2,
    Workflow,
} from 'lucide-react';
import { useId } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { KIND_DOT, KIND_OPTIONS, STANDARD_TEMPLATE } from '../constants';
import { recruitmentPipelineRoutes } from '../routes';
import type { Pipeline, StageDraft, StageKind } from '../types';

type Props = {
    pipeline: Pipeline | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function emptyStage(): StageDraft {
    return { name: '', kind: 'open' };
}

export function PipelineFormDialog({ pipeline, open, onOpenChange }: Props) {
    const isEditing = Boolean(pipeline);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="xl">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Workflow />
                        </ModalIcon>
                    }
                    title={isEditing ? 'Edit pipeline' : 'New pipeline'}
                    description="A named, ordered set of hiring stages — assign it to any job posting."
                />

                {open && (
                    <FormBody
                        key={pipeline?.id ?? 'new'}
                        pipeline={pipeline}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    pipeline,
    onDone,
}: {
    pipeline: Pipeline | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(pipeline);

    const { data, setData, post, processing, errors, transform } = useForm({
        name: pipeline?.name ?? '',
        is_default: pipeline?.is_default ?? false,
        stages: pipeline?.stages?.length
            ? pipeline.stages.map((s): StageDraft => ({
                  name: s.name,
                  kind: s.kind,
              }))
            : [emptyStage()],
    });

    const setStage = (index: number, patch: Partial<StageDraft>) =>
        setData(
            'stages',
            data.stages.map((stage, i) =>
                i === index ? { ...stage, ...patch } : stage,
            ),
        );

    const addStage = () => setData('stages', [...data.stages, emptyStage()]);

    const removeStage = (index: number) =>
        setData(
            'stages',
            data.stages.filter((_, i) => i !== index),
        );

    /** Swap a stage with its neighbour — the list order *is* its position. */
    const moveStage = (index: number, direction: -1 | 1) => {
        const target = index + direction;

        if (target < 0 || target >= data.stages.length) {
            return;
        }

        const reordered = [...data.stages];
        [reordered[index], reordered[target]] = [
            reordered[target],
            reordered[index],
        ];

        setData('stages', reordered);
    };

    const applyTemplate = () =>
        setData(
            'stages',
            STANDARD_TEMPLATE.map((s) => ({ ...s })),
        );

    const stagesError =
        (errors as Record<string, string>)['stages'] ?? undefined;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            stages: payload.stages
                .map((s) => ({ name: s.name.trim(), kind: s.kind }))
                .filter((s) => s.name.length > 0),
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && pipeline) {
            post(recruitmentPipelineRoutes.update(pipeline.hashid), opts);
        } else {
            post(recruitmentPipelineRoutes.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-7">
                <section className="space-y-4">
                    <FormField
                        label="Pipeline name"
                        required
                        error={errors.name}
                    >
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Standard Hiring, Warehouse Hiring"
                            required
                        />
                    </FormField>

                    <Toggle
                        label="Default pipeline"
                        hint="Used automatically for new job postings unless someone picks another."
                        checked={data.is_default}
                        onChange={(v) => setData('is_default', v)}
                    />
                </section>

                <section className="space-y-4">
                    <div className="flex items-start justify-between gap-3 border-b border-border/70 pb-2.5">
                        <div className="min-w-0">
                            <h3 className="text-sm font-semibold tracking-tight">
                                Stages
                            </h3>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                The order candidates move through. Needs exactly
                                one Hired stage and at least one Rejected stage.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="shrink-0"
                            onClick={applyTemplate}
                        >
                            <Sparkles className="size-4" />
                            Start from template
                        </Button>
                    </div>

                    {stagesError && (
                        <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                            {stagesError}
                        </p>
                    )}

                    <div className="space-y-2">
                        {data.stages.map((stage, index) => (
                            <StageRow
                                key={index}
                                index={index}
                                stage={stage}
                                total={data.stages.length}
                                error={
                                    (errors as Record<string, string>)[
                                        `stages.${index}.name`
                                    ]
                                }
                                onChange={(patch) => setStage(index, patch)}
                                onMove={(direction) =>
                                    moveStage(index, direction)
                                }
                                onRemove={() => removeStage(index)}
                            />
                        ))}
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addStage}
                    >
                        <Plus className="size-4" />
                        Add stage
                    </Button>
                </section>
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
                    {isEditing ? 'Save changes' : 'Create pipeline'}
                </Button>
            </ModalFooter>
        </form>
    );
}

function StageRow({
    index,
    stage,
    total,
    error,
    onChange,
    onMove,
    onRemove,
}: {
    index: number;
    stage: StageDraft;
    total: number;
    error?: string;
    onChange: (patch: Partial<StageDraft>) => void;
    onMove: (direction: -1 | 1) => void;
    onRemove: () => void;
}) {
    const position = `Stage ${index + 1}`;

    return (
        <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-2.5">
            <div className="flex shrink-0 flex-col items-center">
                <button
                    type="button"
                    onClick={() => onMove(-1)}
                    disabled={index === 0}
                    className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-30 disabled:hover:text-muted-foreground"
                    aria-label={`Move ${position} up`}
                >
                    <ChevronUp className="size-3.5" />
                </button>
                <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                    {index + 1}
                </span>
                <button
                    type="button"
                    onClick={() => onMove(1)}
                    disabled={index === total - 1}
                    className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-30 disabled:hover:text-muted-foreground"
                    aria-label={`Move ${position} down`}
                >
                    <ChevronDown className="size-3.5" />
                </button>
            </div>

            <div className="grid flex-1 gap-2 sm:grid-cols-[1fr_1fr]">
                <div>
                    <Input
                        value={stage.name}
                        onChange={(e) => onChange({ name: e.target.value })}
                        placeholder="Stage name"
                        aria-label={`${position} name`}
                        aria-invalid={error ? true : undefined}
                    />
                    <InputError message={error} role="alert" className="mt-1" />
                </div>

                <fieldset
                    className="flex items-center gap-3 rounded-md border border-input px-2.5"
                    aria-label={`${position} meaning`}
                >
                    {KIND_OPTIONS.map((option) => (
                        <label
                            key={option.value}
                            className="flex items-center gap-1.5 py-2 text-xs whitespace-nowrap"
                        >
                            <input
                                type="radio"
                                name={`stage-kind-${index}`}
                                checked={stage.kind === option.value}
                                onChange={() =>
                                    onChange({
                                        kind: option.value as StageKind,
                                    })
                                }
                                className="accent-[#0ABFBF]"
                            />
                            <span
                                className={cn(
                                    'size-1.5 rounded-full',
                                    KIND_DOT[option.value],
                                )}
                            />
                            {option.label}
                        </label>
                    ))}
                </fieldset>
            </div>

            <button
                type="button"
                onClick={onRemove}
                className="mt-1.5 rounded-md p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={`Remove ${position}`}
            >
                <Trash2 className="size-4" />
            </button>
        </div>
    );
}

function Toggle({
    label,
    hint,
    checked,
    onChange,
}: {
    label: string;
    hint: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const id = useId();

    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border border-border px-3 py-2.5">
            <div className="min-w-0">
                <Label htmlFor={id} className="cursor-pointer">
                    {label}
                </Label>
                <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
            </div>
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onChange}
                className="shrink-0"
            />
        </div>
    );
}

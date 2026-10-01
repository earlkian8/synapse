import { ChevronDown, ChevronUp, Plus, X } from 'lucide-react';
import { FormSelect } from '@/components/form-select';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    KIND_DOT,
    KIND_LABELS,
    KIND_OPTIONS,
} from '@/features/recruitment-pipelines/constants';
import type {
    StageDraft,
    StageKind,
} from '@/features/recruitment-pipelines/types';
import { cn } from '@/lib/utils';

type Props = {
    stages: StageDraft[];
    errors: Record<string, string>;
    onChange: (stages: StageDraft[]) => void;
};

/**
 * The stages of a hiring process, in the order candidates move through them.
 *
 * Order is the list itself — a stage's position is where it sits here, not a
 * number anybody types. What recruitment actually reads is the **meaning** next
 * to each stage rather than its name (ADR 0029), so that is a choice from three
 * rather than a free-text field, and the running check underneath says whether
 * the process can function yet: somewhere a candidate ends up hired, and
 * somewhere they end up not.
 */
export default function StageEditor({ stages, errors, onChange }: Props) {
    const patch = (index: number, next: Partial<StageDraft>) =>
        onChange(
            stages.map((stage, at) =>
                at === index ? { ...stage, ...next } : stage,
            ),
        );

    /** Swap a stage with its neighbour — the list order *is* its position. */
    const move = (index: number, direction: -1 | 1) => {
        const target = index + direction;

        if (target < 0 || target >= stages.length) {
            return;
        }

        const reordered = [...stages];
        [reordered[index], reordered[target]] = [
            reordered[target],
            reordered[index],
        ];

        onChange(reordered);
    };

    const won = stages.filter((stage) => stage.kind === 'won').length;
    const lost = stages.filter((stage) => stage.kind === 'lost').length;
    const complete = won === 1 && lost >= 1;

    return (
        <div className="flex flex-col gap-2">
            {stages.map((stage, index) => (
                <div
                    // Rows are positional — a stage has no id until it is saved.
                    key={index}
                    className="flex items-start gap-2 rounded-lg border border-dashed border-sidebar-border bg-muted/20 p-2.5"
                >
                    <div className="flex shrink-0 flex-col items-center pt-1">
                        <button
                            type="button"
                            onClick={() => move(index, -1)}
                            disabled={index === 0}
                            aria-label={`Move stage ${index + 1} earlier`}
                            className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-30"
                        >
                            <ChevronUp className="size-3.5" />
                        </button>
                        <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                            {index + 1}
                        </span>
                        <button
                            type="button"
                            onClick={() => move(index, 1)}
                            disabled={index === stages.length - 1}
                            aria-label={`Move stage ${index + 1} later`}
                            className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-30"
                        >
                            <ChevronDown className="size-3.5" />
                        </button>
                    </div>

                    <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-[minmax(0,1fr)_12rem]">
                        <div className="min-w-0">
                            <div className="relative">
                                <span
                                    aria-hidden
                                    className={cn(
                                        'absolute top-1/2 left-3 size-1.5 -translate-y-1/2 rounded-full',
                                        KIND_DOT[stage.kind],
                                    )}
                                />
                                <Input
                                    value={stage.name}
                                    onChange={(event) =>
                                        patch(index, {
                                            name: event.target.value,
                                        })
                                    }
                                    placeholder="Stage name, e.g. Trial shift"
                                    aria-label={`Stage ${index + 1} name`}
                                    className="bg-background pl-7"
                                />
                            </div>
                            <InputError
                                message={errors[`stages.${index}.name`]}
                                className="mt-1"
                            />
                        </div>

                        <FormSelect
                            value={stage.kind}
                            onChange={(value) =>
                                patch(index, { kind: value as StageKind })
                            }
                            // The short form of each meaning: the pipelines
                            // editor spells them out as radio labels, which a
                            // select this narrow would only truncate.
                            options={KIND_OPTIONS.map((option) => ({
                                value: option.value,
                                label: KIND_LABELS[option.value],
                            }))}
                            className="w-full bg-background"
                        />
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() =>
                            onChange(stages.filter((_, at) => at !== index))
                        }
                        aria-label={`Remove stage ${index + 1}`}
                        className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                    >
                        <X className="size-4" />
                    </Button>
                </div>
            ))}

            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange(withNewStage(stages))}
                    disabled={stages.length >= 20}
                >
                    <Plus className="size-4" />
                    Add a stage
                </Button>

                <p
                    className={cn(
                        'text-xs',
                        complete
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : 'text-amber-600 dark:text-amber-400',
                    )}
                >
                    {complete
                        ? `${stages.length} stages, ending in hired or not`
                        : won !== 1
                          ? 'Mark exactly one stage as the hired one'
                          : 'Mark at least one stage as not going through'}
                </p>
            </div>

            <InputError message={errors.stages} className="mt-0.5" />
        </div>
    );
}

/**
 * A new stage, in the place a new stage actually belongs: before the first
 * stage that ends the process. Appending would put "Trial shift" after
 * "Rejected", which is never what anybody meant — the terminal stages are the
 * floor of the list, not part of the order candidates walk.
 */
function withNewStage(stages: StageDraft[]): StageDraft[] {
    const terminal = stages.findIndex((stage) => stage.kind !== 'open');
    const at = terminal === -1 ? stages.length : terminal;

    return [
        ...stages.slice(0, at),
        { name: '', kind: 'open' },
        ...stages.slice(at),
    ];
}

import { useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    ChevronDown,
    FileText,
    RotateCcw,
    Scale,
} from 'lucide-react';
import { useState } from 'react';
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
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { DEFAULT_SAMPLE } from '../constants';
import { attendancePolicyRoutes } from '../routes';
import type {
    AttendancePolicy,
    PolicyPreset,
    PolicySettings,
    PunchSource,
    SampleDay,
} from '../types';
import {
    firstTopicWithError,
    PolicySections,
    RULE_TOPICS,
} from './policy-sections';
import type { ExtraTopic } from './policy-sections';
import { Rule } from './rule-kit';
import { WorkedExample } from './worked-example';

type Props = {
    policy: AttendancePolicy | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    presets: PolicyPreset[];
    fallback: PolicySettings;
    sources: PunchSource[];
};

/**
 * The attendance-policy editor (ADR 0038). The policy is read as what it is — a
 * short rulebook: what it's called and where it started, then a few topics of
 * plain rules (late arrivals, short days, breaks, overtime, clocking in, fine
 * tuning), each a sentence with its numbers filled in. A sample day beside them
 * is judged by the server's own evaluator as the rules change, so a change is
 * seen before it is saved.
 */
export function PolicyFormModal({
    policy,
    open,
    onOpenChange,
    presets,
    fallback,
    sources,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="2xl" className="lg:max-w-[76rem]">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Scale />
                        </ModalIcon>
                    }
                    title={policy ? policy.name : 'New attendance policy'}
                    description="The rules a working day is judged by. Go through the topics one at a time; the sample day shows what your rules do."
                />

                {open && (
                    <FormBody
                        key={policy?.id ?? 'new'}
                        policy={policy}
                        presets={presets}
                        fallback={fallback}
                        sources={sources}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

const BASICS = 'basics';

function FormBody({
    policy,
    presets,
    fallback,
    sources,
    onDone,
}: {
    policy: AttendancePolicy | null;
    presets: PolicyPreset[];
    fallback: PolicySettings;
    sources: PunchSource[];
    onDone: () => void;
}) {
    const firstPreset = presets[0] ?? null;

    const { data, setData, post, processing, errors, clearErrors } = useForm({
        name: policy?.name ?? '',
        description: policy?.description ?? '',
        preset_key: (policy ? policy.preset_key : firstPreset?.key) ?? null,
        is_default: policy?.is_default ?? false,
        settings: policy?.settings ?? firstPreset?.settings ?? fallback,
    });

    const [sample, setSample] = useState<SampleDay>(DEFAULT_SAMPLE);
    const [topic, setTopic] = useState<string>(BASICS);
    const [sampleOpen, setSampleOpen] = useState(false);
    const messages = errors as Record<string, string>;
    const preset = presets.find((item) => item.key === data.preset_key);

    const changeSettings = <G extends keyof PolicySettings>(
        group: G,
        patch: Partial<PolicySettings[G]>,
    ) =>
        setData((current) => ({
            ...current,
            settings: {
                ...current.settings,
                [group]: { ...current.settings[group], ...patch },
            },
        }));

    /** Start (again) from a preset — or, with null, from the built-in rules. */
    const startFrom = (next: PolicyPreset | null) => {
        clearErrors();
        setData((current) => ({
            ...current,
            preset_key: next?.key ?? null,
            settings: next?.settings ?? fallback,
        }));
    };

    const basics: ExtraTopic = {
        key: BASICS,
        title: 'About this policy',
        icon: FileText,
        summary: [
            data.name.trim() || 'Not named yet',
            data.is_default && 'company default',
        ]
            .filter(Boolean)
            .join(', '),
        intro: policy
            ? 'Its name, who it is for, and whether it is the company default.'
            : 'Pick the rules closest to how your company works, then name the policy. You can change any rule afterwards.',
        hasError: Boolean(errors.name || errors.description),
        content: (
            <div className="space-y-7">
                <div className="grid max-w-xl gap-4">
                    <div>
                        <Label htmlFor="policy-name" className="mb-1.5 block">
                            Name
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            id="policy-name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder={preset?.name ?? 'e.g. Head office'}
                            required
                        />
                        <InputError message={errors.name} className="mt-1.5" />
                    </div>
                    <div>
                        <Label
                            htmlFor="policy-description"
                            className="mb-1.5 block"
                        >
                            Who it is for{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="policy-description"
                            value={data.description}
                            onChange={(event) =>
                                setData('description', event.target.value)
                            }
                            placeholder="e.g. Warehouse crews on rotating shifts"
                        />
                        <InputError
                            message={errors.description}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                {!policy && (
                    <StartingPoints
                        presets={presets}
                        chosen={data.preset_key}
                        onChoose={startFrom}
                    />
                )}

                <div className="max-w-xl">
                    <Rule
                        checked={data.is_default}
                        onCheckedChange={(checked) =>
                            setData('is_default', checked)
                        }
                        sentence="Make this the company default."
                        hint="It applies to everyone who isn’t given another policy through their schedule, department or work location."
                    />
                </div>

                {policy && preset && (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-dashed border-border px-4 py-3 text-sm">
                        <span className="text-muted-foreground">
                            Started from <strong>{preset.name}</strong>.
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => startFrom(preset)}
                        >
                            <RotateCcw className="size-3.5" />
                            Put its rules back
                        </Button>
                    </div>
                )}

                {policy && (
                    <p className="max-w-xl text-[0.8125rem] leading-relaxed text-muted-foreground">
                        Saving changes how days are judged from now on. Days
                        already recorded keep the rules they were judged by
                        until you re-apply them from the attendance board.
                    </p>
                )}
            </div>
        ),
    };

    const order = [BASICS, ...RULE_TOPICS.map((item) => item.key)];
    const index = Math.max(0, order.indexOf(topic));
    const previous = index > 0 ? order[index - 1] : null;
    const next = index < order.length - 1 ? order[index + 1] : null;
    const titleOf = (key: string) =>
        key === BASICS
            ? basics.title
            : (RULE_TOPICS.find((item) => item.key === key)?.title ?? key);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        post(
            policy
                ? attendancePolicyRoutes.update(policy.hashid)
                : attendancePolicyRoutes.store,
            {
                preserveScroll: true,
                onSuccess: () => onDone(),
                onError: (bag) => {
                    // The bag just returned, not the form's: the topics read
                    // the form's errors only on the next render.
                    const first =
                        bag.name || bag.description
                            ? BASICS
                            : firstTopicWithError(
                                  bag as Record<string, string>,
                              );

                    if (first) {
                        setTopic(first);
                    }
                },
            },
        );
    };

    const example = (
        <WorkedExample
            settings={data.settings}
            sample={sample}
            onSampleChange={setSample}
        />
    );

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start lg:gap-7">
                <PolicySections
                    settings={data.settings}
                    onChange={changeSettings}
                    errors={messages}
                    sources={sources}
                    before={[basics]}
                    topic={topic}
                    onTopicChange={setTopic}
                />

                <div className="hidden lg:sticky lg:top-0 lg:block">
                    {example}
                </div>

                <Collapsible
                    open={sampleOpen}
                    onOpenChange={setSampleOpen}
                    className="rounded-xl border border-border lg:hidden"
                >
                    <CollapsibleTrigger className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium">
                        Try these rules on a sample day
                        <ChevronDown
                            className={cn(
                                'size-4 text-muted-foreground transition-transform',
                                sampleOpen && 'rotate-180',
                            )}
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="border-t border-border [&>section]:rounded-none [&>section]:border-0">
                        {example}
                    </CollapsibleContent>
                </Collapsible>
            </ModalBody>

            <ModalFooter className="justify-between">
                <div className="flex items-center gap-1">
                    {previous && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setTopic(previous)}
                            aria-label={`Back to ${titleOf(previous)}`}
                        >
                            <ArrowLeft className="size-4" />
                            <span className="hidden sm:inline">Back</span>
                        </Button>
                    )}
                    {next && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setTopic(next)}
                        >
                            <span className="hidden sm:inline">
                                Next: {titleOf(next)}
                            </span>
                            <span className="sm:hidden">Next</span>
                            <ArrowRight className="size-4" />
                        </Button>
                    )}
                </div>
                <div className="flex items-center gap-2">
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
                        {policy ? 'Save changes' : 'Create policy'}
                    </Button>
                </div>
            </ModalFooter>
        </form>
    );
}

/**
 * Where a new policy's rules start: each preset as a card leading with the
 * rules that make it different, and the built-in rules as the plain start.
 */
function StartingPoints({
    presets,
    chosen,
    onChoose,
}: {
    presets: PolicyPreset[];
    chosen: string | null;
    onChoose: (preset: PolicyPreset | null) => void;
}) {
    const cards = [
        ...presets.map((preset) => ({
            key: preset.key,
            name: preset.name,
            description: preset.description,
            highlights: preset.highlights,
            preset,
        })),
        {
            key: null,
            name: 'A plain start',
            description:
                'Exact times, each schedule’s grace, overtime after the shift’s hours, breaks as punched.',
            highlights: [],
            preset: null,
        },
    ];

    return (
        <fieldset>
            <legend className="mb-1 text-[0.9375rem] font-medium">
                Start from
            </legend>
            <p className="mb-3 text-[0.8125rem] text-muted-foreground">
                Choosing another start replaces the rules with its own.
            </p>
            <div
                role="radiogroup"
                aria-label="Start from"
                className="grid gap-2.5 sm:grid-cols-2"
            >
                {cards.map((card) => {
                    const active = card.key === chosen;

                    return (
                        <button
                            key={card.key ?? 'plain'}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            onClick={() => onChoose(card.preset)}
                            className={cn(
                                'relative flex flex-col items-start gap-1 rounded-xl border px-4 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none',
                                active
                                    ? 'border-[#0a8b91] bg-[#0ABFBF]/[0.06] ring-1 ring-[#0a8b91]'
                                    : 'border-border hover:border-foreground/25 hover:bg-muted/40',
                            )}
                        >
                            <span className="flex w-full items-start justify-between gap-2">
                                <span className="text-sm font-semibold">
                                    {card.name}
                                </span>
                                <span
                                    aria-hidden
                                    className={cn(
                                        'flex size-5 shrink-0 items-center justify-center rounded-full border',
                                        active
                                            ? 'border-[#0a8b91] bg-[#0a8b91] text-white'
                                            : 'border-border',
                                    )}
                                >
                                    {active && <Check className="size-3" />}
                                </span>
                            </span>
                            <span className="text-[0.8125rem] leading-snug text-muted-foreground">
                                {card.description}
                            </span>
                            {card.highlights.length > 0 && (
                                <ul className="mt-1 space-y-0.5 text-xs text-foreground/80">
                                    {card.highlights.slice(0, 3).map((line) => (
                                        <li key={line} className="flex gap-1.5">
                                            <span
                                                aria-hidden
                                                className="mt-[0.4rem] size-1 shrink-0 rounded-full bg-[#0ABFBF]"
                                            />
                                            {line}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </button>
                    );
                })}
            </div>
        </fieldset>
    );
}

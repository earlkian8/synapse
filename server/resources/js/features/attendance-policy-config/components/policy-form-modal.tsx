import { useForm } from '@inertiajs/react';
import { RotateCcw, Scale } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
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
import { PolicySections } from './policy-sections';
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
 * The attendance-policy editor (ADR 0038): a name, where it started from, and
 * its settings in groups — beside a worked example that judges a sample day by
 * whatever is on screen, so a change is seen before it is saved.
 *
 * A new policy starts from a preset or from the built-in rules; one made from a
 * preset can be put back to it.
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
            <ModalContent size="2xl" className="lg:max-w-6xl">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Scale />
                        </ModalIcon>
                    }
                    title={policy ? policy.name : 'New attendance policy'}
                    description="How a day is judged: lateness, short days, rounding, breaks, overtime and night work."
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

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        post(
            policy
                ? attendancePolicyRoutes.update(policy.hashid)
                : attendancePolicyRoutes.store,
            { preserveScroll: true, onSuccess: () => onDone() },
        );
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
                <div className="min-w-0 space-y-6">
                    {!policy && (
                        <div className="space-y-2">
                            <Label className="block">Start from</Label>
                            <div className="flex flex-wrap gap-1.5">
                                {presets.map((item) => (
                                    <StartChip
                                        key={item.key}
                                        active={data.preset_key === item.key}
                                        onClick={() => startFrom(item)}
                                        title={item.description}
                                    >
                                        {item.name}
                                    </StartChip>
                                ))}
                                <StartChip
                                    active={data.preset_key === null}
                                    onClick={() => startFrom(null)}
                                    title="Exact times, overtime after each day’s hours, breaks as punched."
                                >
                                    The built-in rules
                                </StartChip>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {preset?.description ??
                                    'What attendance does when no policy applies — a plain start to adjust.'}
                            </p>
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <Label
                                htmlFor="policy-name"
                                className="mb-1.5 block"
                            >
                                Name
                                <span className="ml-0.5 text-destructive">
                                    *
                                </span>
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
                            <InputError
                                message={errors.name}
                                className="mt-1.5"
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <Label
                                htmlFor="policy-description"
                                className="mb-1.5 block"
                            >
                                Who it is for{' '}
                                <span className="text-muted-foreground">
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
                        </div>
                        <label className="flex items-start justify-between gap-4 rounded-lg border border-border px-3 py-2.5 sm:col-span-2">
                            <span>
                                <span className="block text-sm font-medium">
                                    The company default
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    Applies to anyone whose assignment, schedule
                                    and department name no policy.
                                </span>
                            </span>
                            <Switch
                                checked={data.is_default}
                                onCheckedChange={(checked) =>
                                    setData('is_default', checked)
                                }
                            />
                        </label>
                    </div>

                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold">The rules</h3>
                            {policy && preset && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 text-xs text-muted-foreground"
                                    onClick={() => startFrom(preset)}
                                >
                                    <RotateCcw className="size-3.5" />
                                    Reset to {preset.name}
                                </Button>
                            )}
                        </div>
                        <PolicySections
                            key={data.preset_key ?? 'built-in'}
                            settings={data.settings}
                            onChange={changeSettings}
                            errors={messages}
                            sources={sources}
                        />
                    </div>

                    {policy && (
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            Saving changes how days are judged from now on. Days
                            already recorded keep the rules they were judged by
                            until you re-apply them from the attendance board.
                        </p>
                    )}
                </div>

                <div className="lg:sticky lg:top-0">
                    <WorkedExample
                        settings={data.settings}
                        sample={sample}
                        onSampleChange={setSample}
                    />
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
                    {policy ? 'Save changes' : 'Create policy'}
                </Button>
            </ModalFooter>
        </form>
    );
}

function StartChip({
    active,
    onClick,
    title,
    children,
}: {
    active: boolean;
    onClick: () => void;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            title={title}
            className={cn(
                'rounded-full border px-3 py-1 text-xs transition-colors',
                active
                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 font-medium text-[#0a8b91] dark:text-[#0ABFBF]'
                    : 'border-input text-muted-foreground hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

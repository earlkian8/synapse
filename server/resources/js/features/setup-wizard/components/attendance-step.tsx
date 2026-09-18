import { useForm } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { PolicySections } from '@/features/attendance-policy-config/components/policy-sections';
import { WorkedExample } from '@/features/attendance-policy-config/components/worked-example';
import {
    DEFAULT_SAMPLE,
    PUNCH_SOURCES,
} from '@/features/attendance-policy-config/constants';
import type {
    PolicyPreset,
    PolicySettings,
    SampleDay,
} from '@/features/attendance-policy-config/types';
import { cn } from '@/lib/utils';
import { setupWizardRoutes } from '../routes';
import AlreadyConfigured from './already-configured';
import ChoiceCard from './choice-card';
import StepBody from './step-body';
import StepFooter from './step-footer';

type Props = {
    presets: PolicyPreset[];
    existingPolicies: string[];
    existingSchedules: string[];
    onSaved: () => void;
    onBack: () => void;
    onSkip: () => void;
    skipping: boolean;
};

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as const;

/**
 * Step 4 — how the company's attendance days are judged (ADR 0038), and the
 * hours most of its people work.
 *
 * A preset is always where it starts: each card leads with the rules that make
 * it different, because those are the whole difference between them. **Customise
 * these rules** opens the chosen preset up in the same editor Company Setup uses,
 * with a worked example beside it; what is saved is an ordinary policy that
 * screen reads back, and it becomes the company default so it applies from the
 * first punch.
 */
export default function AttendanceStep({
    presets,
    existingPolicies,
    existingSchedules,
    onSaved,
    onBack,
    onSkip,
    skipping,
}: Props) {
    const first = presets[0];

    const { data, setData, post, transform, processing, errors, clearErrors } =
        useForm({
            preset: first?.key ?? '',
            name: '',
            customised: false,
            settings: (first?.settings ?? null) as PolicySettings | null,
            schedule: {
                create: existingSchedules.length === 0,
                name: 'Office Hours',
                start: '08:00',
                end: '17:00',
                days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as string[],
            },
        });

    const [sample, setSample] = useState<SampleDay>(DEFAULT_SAMPLE);
    const chosen = presets.find((preset) => preset.key === data.preset);
    const messages = errors as Record<string, string>;

    const choose = (preset: PolicyPreset) => {
        clearErrors();
        setData((current) => ({
            ...current,
            preset: preset.key,
            settings: preset.settings,
        }));
    };

    const customise = (customised: boolean) => {
        clearErrors();
        setData((current) => ({
            ...current,
            customised,
            // Back to the cards means back to the preset as it is.
            settings: customised
                ? current.settings
                : (chosen?.settings ?? null),
        }));
    };

    const changeSettings = <G extends keyof PolicySettings>(
        group: G,
        patch: Partial<PolicySettings[G]>,
    ) =>
        setData((current) =>
            current.settings === null
                ? current
                : {
                      ...current,
                      settings: {
                          ...current.settings,
                          [group]: { ...current.settings[group], ...patch },
                      },
                  },
        );

    const setSchedule = (patch: Partial<typeof data.schedule>) =>
        setData((current) => ({
            ...current,
            schedule: { ...current.schedule, ...patch },
        }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            preset: payload.preset,
            name: payload.name.trim() || null,
            customised: payload.customised,
            // An adopted preset's rules are the server's to fill in.
            ...(payload.customised ? { settings: payload.settings } : {}),
            schedule: payload.schedule.create
                ? payload.schedule
                : { create: false },
        }));

        post(setupWizardRoutes.attendance, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onSaved,
        });
    };

    const policyName = data.name.trim() || chosen?.name || '';

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <StepBody>
                <AlreadyConfigured
                    names={existingPolicies}
                    noun="attendance policy"
                    where="Company Setup → Attendance Policies"
                />

                {data.customised && data.settings !== null ? (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 className="text-sm font-semibold text-foreground">
                                    Your rules
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Starting from {chosen?.name}. Change what
                                    your company does differently; the example
                                    shows the effect as you go.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => customise(false)}
                                className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >
                                <ArrowLeft className="size-3.5" />
                                Back to the presets
                            </button>
                        </div>

                        <PolicySections
                            settings={data.settings}
                            onChange={changeSettings}
                            errors={messages}
                            sources={PUNCH_SOURCES}
                        />

                        <WorkedExample
                            settings={data.settings}
                            sample={sample}
                            onSampleChange={setSample}
                        />
                    </>
                ) : (
                    <div className="flex flex-col gap-2.5">
                        {presets.map((preset) => (
                            <ChoiceCard
                                key={preset.key}
                                mode="single"
                                name="attendance-preset"
                                value={preset.key}
                                checked={data.preset === preset.key}
                                onChange={() => choose(preset)}
                                title={preset.name}
                                description={preset.description}
                                action={
                                    data.preset === preset.key ? (
                                        <button
                                            type="button"
                                            onClick={() => customise(true)}
                                            className="mt-1 ml-7 text-[11px] font-medium text-[#0a8b91] underline-offset-4 hover:underline dark:text-[#0ABFBF]"
                                        >
                                            Customise these rules
                                        </button>
                                    ) : undefined
                                }
                            >
                                <ul className="mt-1 grid gap-1 pl-7 sm:grid-cols-2">
                                    {preset.highlights.map((line) => (
                                        <li
                                            key={line}
                                            className="flex items-start gap-1.5 text-[11px] leading-snug text-foreground/80"
                                        >
                                            <Check
                                                aria-hidden
                                                className="mt-px size-3 shrink-0 text-[#0a8b91] dark:text-[#0ABFBF]"
                                            />
                                            {line}
                                        </li>
                                    ))}
                                </ul>
                            </ChoiceCard>
                        ))}
                        <InputError message={errors.preset} />
                    </div>
                )}

                <div className="max-w-sm">
                    <Label htmlFor="policy-name" className="mb-1.5 block">
                        Call it something else{' '}
                        <span className="text-muted-foreground">
                            (optional)
                        </span>
                    </Label>
                    <Input
                        id="policy-name"
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        placeholder={chosen?.name ?? 'Head office'}
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <DefaultSchedule
                    schedule={data.schedule}
                    existing={existingSchedules}
                    onChange={setSchedule}
                    errors={messages}
                />

                <p className="text-xs leading-relaxed text-muted-foreground">
                    {existingPolicies.length > 0
                        ? 'Your current company default stays as it is; this policy can be named on a schedule, a department or an assignment, or made the default later.'
                        : 'The policy becomes the company default, so it applies to everybody unless a schedule, a department or someone’s own assignment names a different one.'}{' '}
                    Every rule can be changed later; a day already recorded
                    keeps the rules it was judged by.
                </p>
            </StepBody>

            <StepFooter
                onBack={onBack}
                onSkip={onSkip}
                processing={processing}
                skipping={skipping}
                disabled={data.preset === ''}
                note={
                    policyName
                        ? `Creates "${policyName}"${data.schedule.create && data.schedule.name.trim() ? ` and "${data.schedule.name.trim()}"` : ''}`
                        : undefined
                }
            />
        </form>
    );
}

/** The hours most people work — written as the company's default schedule. */
function DefaultSchedule({
    schedule,
    existing,
    onChange,
    errors,
}: {
    schedule: {
        create: boolean;
        name: string;
        start: string;
        end: string;
        days: string[];
    };
    existing: string[];
    onChange: (patch: Partial<typeof schedule>) => void;
    errors: Record<string, string>;
}) {
    const toggleDay = (day: string) =>
        onChange({
            days: schedule.days.includes(day)
                ? schedule.days.filter((item) => item !== day)
                : WEEKDAYS.filter(
                      (item) => item === day || schedule.days.includes(item),
                  ),
        });

    return (
        <section className="space-y-4 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <label className="flex items-start justify-between gap-4">
                <span>
                    <span className="block text-sm font-semibold text-foreground">
                        Set up the hours most people work
                    </span>
                    <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                        {existing.length > 0
                            ? `You already have ${existing.join(', ')}. Add another only if most people work something else.`
                            : 'Becomes the company’s default schedule. Night shifts, split shifts and rotations are set up in Work Schedule & Holidays.'}
                    </span>
                </span>
                <Switch
                    checked={schedule.create}
                    onCheckedChange={(create) => onChange({ create })}
                />
            </label>

            {schedule.create && (
                <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <div>
                        <Label htmlFor="schedule-name" className="mb-1.5 block">
                            Name
                        </Label>
                        <Input
                            id="schedule-name"
                            value={schedule.name}
                            onChange={(event) =>
                                onChange({ name: event.target.value })
                            }
                        />
                        <InputError
                            message={errors['schedule.name']}
                            className="mt-1.5"
                        />
                    </div>
                    <fieldset>
                        <legend className="mb-1.5 text-sm font-medium">
                            Hours
                        </legend>
                        <div className="flex items-center gap-2">
                            <Input
                                type="time"
                                value={schedule.start}
                                onChange={(event) =>
                                    onChange({ start: event.target.value })
                                }
                                aria-label="Starts at"
                                className="w-28"
                            />
                            <span className="text-muted-foreground">–</span>
                            <Input
                                type="time"
                                value={schedule.end}
                                onChange={(event) =>
                                    onChange({ end: event.target.value })
                                }
                                aria-label="Ends at"
                                className="w-28"
                            />
                        </div>
                        <InputError
                            message={errors['schedule.end']}
                            className="mt-1.5"
                        />
                    </fieldset>
                    <fieldset className="sm:col-span-2">
                        <legend className="mb-1.5 text-sm font-medium">
                            Working days
                        </legend>
                        <div className="flex flex-wrap gap-1.5">
                            {WEEKDAYS.map((day) => {
                                const on = schedule.days.includes(day);

                                return (
                                    <button
                                        key={day}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => toggleDay(day)}
                                        className={cn(
                                            'w-11 rounded-md border py-1.5 text-xs transition-colors',
                                            on
                                                ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 font-medium text-[#0a8b91] dark:text-[#0ABFBF]'
                                                : 'border-input text-muted-foreground hover:bg-muted',
                                        )}
                                    >
                                        {day}
                                    </button>
                                );
                            })}
                        </div>
                        <InputError
                            message={errors['schedule.days']}
                            className="mt-1.5"
                        />
                    </fieldset>
                </div>
            )}
        </section>
    );
}

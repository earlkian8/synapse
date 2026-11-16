import { useForm } from '@inertiajs/react';
import { BookOpenCheck, Plus, X } from 'lucide-react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
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
import { Spinner } from '@/components/ui/spinner';
import { TEXTAREA } from '@/features/training/constants';
import { disciplineRoutes } from '../routes';
import type { Gravity, Offense, Penalty } from '../types';

type Step = { action: Penalty; days: string };

const ORDINALS = [
    'First time',
    'Second time',
    'Third time',
    'Fourth time',
    'Fifth time',
    'Sixth time',
    'Seventh time',
    'Eighth time',
];

/**
 * An offense in the code of conduct (ADR 0105): what it is, how grave, the
 * ground it rests on, and its ladder — the penalty for the first time, the
 * second, and so on; the last step repeats.
 */
export function OffenseFormDialog({
    offense,
    open,
    onOpenChange,
    options,
}: {
    offense: Offense | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: {
        gravities: Record<Gravity, string>;
        grounds: Record<string, string>;
        justCauses: string[];
        penalties: Record<Penalty, string>;
    };
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <BookOpenCheck />
                        </ModalIcon>
                    }
                    title={offense ? `Edit ${offense.code}` : 'Add an offense'}
                    description="As your employee handbook words it. Cases already decided keep the penalty they were given."
                />
                {open && (
                    <Body
                        key={offense?.id ?? 'new'}
                        offense={offense}
                        options={options}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    offense,
    options,
    onDone,
}: {
    offense: Offense | null;
    options: Parameters<typeof OffenseFormDialog>[0]['options'];
    onDone: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        code: offense?.code ?? '',
        title: offense?.title ?? '',
        description: offense?.description ?? '',
        gravity: (offense?.gravity ?? 'minor') as Gravity,
        ground: offense?.ground ?? 'company_rule',
        reckoning_months: String(offense?.reckoning_months ?? 12),
        is_active: offense?.is_active ?? true,
        penalties: (
            offense?.penalties ?? [
                { action: 'verbal_warning' },
                { action: 'written_warning' },
            ]
        ).map((step) => ({
            action: step.action,
            days: step.days ? String(step.days) : '',
        })) as Step[],
    });

    const errorFor = (key: string) =>
        (errors as Record<string, string | undefined>)[key];
    const justCause = options.justCauses.includes(data.ground);

    const setStep = (index: number, step: Partial<Step>) =>
        setData(
            'penalties',
            data.penalties.map((current, i) =>
                i === index ? { ...current, ...step } : current,
            ),
        );

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                transform((payload) => ({
                    ...payload,
                    reckoning_months: Number(payload.reckoning_months),
                    penalties: payload.penalties.map((step) => ({
                        action: step.action,
                        days:
                            step.action === 'suspension' && step.days
                                ? Number(step.days)
                                : null,
                    })),
                }));
                post(
                    offense
                        ? disciplineRoutes.codeUpdate(offense.id)
                        : disciplineRoutes.codeStore,
                    {
                        preserveScroll: true,
                        onSuccess: onDone,
                    },
                );
            }}
            className="flex min-h-0 flex-1 flex-col"
        >
            <ModalBody className="space-y-5">
                <div className="grid gap-5 sm:grid-cols-[8rem_1fr]">
                    <FormField label="Code" required error={errors.code}>
                        <Input
                            value={data.code}
                            onChange={(e) =>
                                setData('code', e.target.value.toUpperCase())
                            }
                            maxLength={20}
                            placeholder="ATT-02"
                        />
                    </FormField>
                    <FormField label="Offense" required error={errors.title}>
                        <Input
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            maxLength={160}
                            placeholder="Absence without leave"
                        />
                    </FormField>
                </div>

                <FormField label="What it covers" error={errors.description}>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={2}
                        maxLength={1000}
                        className={TEXTAREA}
                    />
                </FormField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label="How grave" error={errors.gravity}>
                        <FormSelect
                            value={data.gravity}
                            onChange={(value) =>
                                setData('gravity', value as Gravity)
                            }
                            options={Object.entries(options.gravities).map(
                                ([value, label]) => ({ value, label }),
                            )}
                        />
                    </FormField>
                    <FormField
                        label="Ground"
                        error={errors.ground}
                        hint={
                            justCause
                                ? 'A just cause: it can end in dismissal.'
                                : 'A company rule by itself is never a ground to dismiss.'
                        }
                    >
                        <FormSelect
                            value={data.ground}
                            onChange={(value) => setData('ground', value)}
                            options={Object.entries(options.grounds).map(
                                ([value, label]) => ({ value, label }),
                            )}
                        />
                    </FormField>
                </div>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-medium">Penalties</legend>
                    <ol className="space-y-2">
                        {data.penalties.map((step, index) => (
                            <li
                                key={index}
                                className="grid grid-cols-[6.5rem_1fr_auto] items-start gap-2 sm:grid-cols-[7.5rem_1fr_7rem_auto]"
                            >
                                <span className="pt-2 text-sm text-muted-foreground">
                                    {ORDINALS[index]}
                                </span>
                                <div>
                                    <FormSelect
                                        value={step.action}
                                        onChange={(value) =>
                                            setStep(index, {
                                                action: value as Penalty,
                                            })
                                        }
                                        options={Object.entries(
                                            options.penalties,
                                        )
                                            .filter(
                                                ([value]) =>
                                                    justCause ||
                                                    value !== 'dismissal',
                                            )
                                            .map(([value, label]) => ({
                                                value,
                                                label,
                                            }))}
                                    />
                                    {errorFor(`penalties.${index}.action`) && (
                                        <p className="mt-1 text-xs text-destructive">
                                            {errorFor(
                                                `penalties.${index}.action`,
                                            )}
                                        </p>
                                    )}
                                </div>
                                {step.action === 'suspension' ? (
                                    <div className="col-start-2 sm:col-start-auto">
                                        <Input
                                            type="number"
                                            min={1}
                                            max={30}
                                            value={step.days}
                                            onChange={(e) =>
                                                setStep(index, {
                                                    days: e.target.value,
                                                })
                                            }
                                            aria-label={`${ORDINALS[index]}: days of suspension`}
                                            placeholder="Days"
                                        />
                                        {errorFor(
                                            `penalties.${index}.days`,
                                        ) && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {errorFor(
                                                    `penalties.${index}.days`,
                                                )}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <span className="hidden sm:block" />
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={data.penalties.length === 1}
                                    onClick={() =>
                                        setData(
                                            'penalties',
                                            data.penalties.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                    aria-label={`Remove the ${ORDINALS[index].toLowerCase()} step`}
                                >
                                    <X className="size-4" />
                                </Button>
                            </li>
                        ))}
                    </ol>
                    {data.penalties.length < 8 && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setData('penalties', [
                                    ...data.penalties,
                                    {
                                        action:
                                            data.penalties.at(-1)?.action ??
                                            'written_warning',
                                        days: '',
                                    },
                                ])
                            }
                        >
                            <Plus className="size-4" />
                            Add the next time
                        </Button>
                    )}
                    <p className="text-xs text-muted-foreground">
                        The last step repeats for every time after it.
                    </p>
                </fieldset>

                <FormField
                    label="Counts towards the next for"
                    error={errors.reckoning_months}
                    hint="Months after a decision that it still makes the next one a repeat."
                >
                    <div className="flex items-center gap-2">
                        <Input
                            type="number"
                            min={1}
                            max={60}
                            value={data.reckoning_months}
                            onChange={(e) =>
                                setData('reckoning_months', e.target.value)
                            }
                            className="w-24"
                        />
                        <span className="text-sm text-muted-foreground">
                            months
                        </span>
                    </div>
                </FormField>
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {offense ? 'Save offense' : 'Add offense'}
                </Button>
            </ModalFooter>
        </form>
    );
}

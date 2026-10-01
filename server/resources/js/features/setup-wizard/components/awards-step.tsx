import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { AwardTypesManager } from '@/features/award-types-config/components/award-types-manager';
import type { AwardTypeSetupPageProps } from '@/features/award-types-config/types';
import { setupWizardRoutes } from '../routes';
import type { AwardTypeBlueprint, StepControls } from '../types';
import ChoiceCard from './choice-card';
import SectionHeading from './section-heading';
import StepBody from './step-body';
import StepFooter, { continueAction } from './step-footer';
import SuggestionsPanel, { useSuggestionsOpen } from './suggestions-panel';

type Props = StepControls & {
    screen: AwardTypeSetupPageProps;
    blueprints: AwardTypeBlueprint[];
    existing: string[];
};

/**
 * The recognitions the company gives out. The common ones are offered but none
 * is ticked — what a company celebrates is its own call — and the Award Types
 * editor sits under them for renaming, recolouring, adding its own and retiring
 * one it no longer gives.
 */
export default function AwardsStep({
    screen,
    blueprints,
    existing,
    ...controls
}: Props) {
    const [open, setOpen] = useSuggestionsOpen(controls.configured);
    // Which button started the save — the tray's (add, and stay) or the
    // footer's (add, and continue) — so only that one spins.
    const [continuing, setContinuing] = useState(false);

    const { data, setData, post, processing, errors, clearErrors } = useForm({
        keys: [] as string[],
    });

    const taken = new Set(existing.map((name) => name.toLowerCase()));
    const remaining = blueprints.filter(
        (blueprint) => !taken.has(blueprint.name.toLowerCase()),
    ).length;

    const toggle = (key: string, checked: boolean) => {
        clearErrors('keys');

        setData(
            'keys',
            checked
                ? [...data.keys, key]
                : data.keys.filter((value) => value !== key),
        );
    };

    const total = data.keys.length;

    /** Add the ticked awards — then stay to adjust them, or move on. */
    const submit = (andContinue: boolean) => {
        setContinuing(andContinue);

        post(setupWizardRoutes['award-types'], {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setContinuing(false),
            onSuccess: () => {
                setData('keys', []);

                if (andContinue) {
                    controls.onNext();
                } else {
                    setOpen(false);
                }
            },
        });
    };

    const pending = open && total > 0;
    const addLabel = `Add ${total} ${total === 1 ? 'award' : 'awards'}`;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <StepBody>
                <SuggestionsPanel
                    open={open}
                    onOpenChange={setOpen}
                    collapsible={controls.configured}
                    description="Tick the ones you give. Each is only a name, a meaning and a colour — the reason is written each time an award is given."
                    summary={
                        remaining === 0
                            ? 'Every suggested award is already added'
                            : `${remaining} common ${remaining === 1 ? 'award' : 'awards'} you haven't added`
                    }
                    onSubmit={() => submit(false)}
                    submitLabel={total === 0 ? 'Add awards' : addLabel}
                    submitDisabled={total === 0}
                    processing={processing && !continuing}
                >
                    <div className="grid gap-2.5 sm:grid-cols-2">
                        {blueprints.map((blueprint) => {
                            const already = taken.has(
                                blueprint.name.toLowerCase(),
                            );

                            return (
                                <ChoiceCard
                                    key={blueprint.key}
                                    mode="multiple"
                                    name="award-types"
                                    value={blueprint.key}
                                    checked={data.keys.includes(blueprint.key)}
                                    onChange={(checked) =>
                                        toggle(blueprint.key, checked)
                                    }
                                    disabled={already}
                                    title={
                                        <span className="flex items-center gap-2">
                                            <span
                                                aria-hidden
                                                className="size-2 shrink-0 rounded-full"
                                                style={{
                                                    background: blueprint.color,
                                                }}
                                            />
                                            {blueprint.name}
                                        </span>
                                    }
                                    description={blueprint.description}
                                    aside={
                                        already ? 'Already added' : undefined
                                    }
                                />
                            );
                        })}
                    </div>

                    <InputError message={errors.keys} />
                </SuggestionsPanel>

                <AwardTypesManager
                    {...screen}
                    heading={
                        <SectionHeading
                            title="Your awards"
                            hint="Rename or recolour one, add one of your own, or archive one you no longer give. Awards are given from Awards & Recognition."
                        />
                    }
                />
            </StepBody>

            <StepFooter
                onBack={controls.onBack}
                onSkip={controls.onSkip}
                busy={controls.busy}
                skipping={controls.skipping}
                primary={
                    pending
                        ? {
                              label: `${addLabel} and continue`,
                              onClick: () => submit(true),
                              processing: processing && continuing,
                              disabled: processing,
                          }
                        : continueAction(controls)
                }
                note={
                    pending || controls.configured
                        ? undefined
                        : 'Add an award, or skip this step for now.'
                }
            />
        </div>
    );
}

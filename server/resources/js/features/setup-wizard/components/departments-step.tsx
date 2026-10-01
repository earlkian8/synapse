import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { DepartmentsManager } from '@/features/departments/components/departments-manager';
import type { DepartmentsPageProps } from '@/features/departments/types';
import { setupWizardRoutes } from '../routes';
import type {
    DepartmentBlueprint,
    DepartmentDraft,
    StepControls,
} from '../types';
import ChoiceCard from './choice-card';
import CustomSection, { CustomRow } from './custom-section';
import SectionHeading from './section-heading';
import StepBody from './step-body';
import StepFooter, { continueAction } from './step-footer';
import SuggestionsPanel, { useSuggestionsOpen } from './suggestions-panel';

type Props = StepControls & {
    screen: DepartmentsPageProps;
    blueprints: DepartmentBlueprint[];
    existing: string[];
};

/**
 * The org structure. Tick the functions the company has, reword the ones it
 * calls something else, and add the ones only it has — then shape them in the
 * same board Company Setup uses: nest them, give each a head, the schedule and
 * policy its people default to, and the positions under it.
 *
 * Nothing is pre-ticked: a department list is the one thing on this screen that
 * really is different at every company, and a pre-filled one would be adopted
 * unread. A ticked suggestion is sent as a code and resolved server-side, so its
 * wording is not the client's to decide — **Customise** is how a company says it
 * wants different wording, and moves that department into its own list, where it
 * carries the name, code and description the company gave it.
 */
export default function DepartmentsStep({
    screen,
    blueprints,
    existing,
    ...controls
}: Props) {
    const [open, setOpen] = useSuggestionsOpen(controls.configured);
    // Which button started the save — the tray's (add, and stay) or the
    // footer's (add, and continue) — so only that one spins.
    const [continuing, setContinuing] = useState(false);

    const {
        data,
        setData,
        post,
        processing,
        errors,
        clearErrors,
        transform,
        reset,
    } = useForm({
        codes: [] as string[],
        custom: [] as DepartmentDraft[],
    });

    const taken = new Set(existing.map((name) => name.toLowerCase()));

    // A suggestion the company has taken over is no longer on offer: it is in
    // the list below, in that company's own words.
    const customised = new Set(
        data.custom
            .map((row) => row.source)
            .filter((code): code is string => code !== null),
    );

    const toggle = (code: string, checked: boolean) => {
        clearErrors('codes');

        setData(
            'codes',
            checked
                ? [...data.codes, code]
                : data.codes.filter((value) => value !== code),
        );
    };

    const setRow = (index: number, patch: Partial<DepartmentDraft>) => {
        setData(
            'custom',
            data.custom.map((row, at) =>
                at === index ? { ...row, ...patch } : row,
            ),
        );
    };

    const addRow = (draft: DepartmentDraft) => {
        clearErrors('codes');
        setData('custom', [...data.custom, draft]);
    };

    /** Take a suggestion over: out of the ticked list, into the company's own. */
    const customise = (blueprint: DepartmentBlueprint) => {
        setData((current) => ({
            codes: current.codes.filter((code) => code !== blueprint.code),
            custom: [
                ...current.custom,
                {
                    name: blueprint.name,
                    code: blueprint.code,
                    description: blueprint.description,
                    source: blueprint.code,
                },
            ],
        }));
    };

    const total =
        data.codes.length +
        data.custom.filter((row) => row.name.trim() !== '').length;

    const remaining = blueprints.filter(
        (blueprint) => !taken.has(blueprint.name.toLowerCase()),
    ).length;

    /** Add what was picked — then stay to shape it, or move straight on. */
    const submit = (andContinue: boolean) => {
        setContinuing(andContinue);

        transform((payload) => ({
            ...payload,
            // `source` is the wizard's own bookkeeping — which suggestion a row
            // started as — and means nothing to the server.
            custom: payload.custom.map((row) => ({
                name: row.name,
                code: row.code,
                description: row.description,
            })),
        }));

        post(setupWizardRoutes.departments, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setContinuing(false),
            onSuccess: () => {
                reset();

                if (andContinue) {
                    controls.onNext();
                } else {
                    setOpen(false);
                }
            },
        });
    };

    const pending = open && total > 0;
    const addLabel = `Add ${total} ${total === 1 ? 'department' : 'departments'}`;

    const rows = data.custom.map((row, index) => (
        <CustomRow
            // Rows are positional — a typed department has no id until it is saved.
            key={index}
            label={`Department ${index + 1}`}
            removeLabel={`Remove ${row.name || `department ${index + 1}`}`}
            onRemove={() =>
                setData(
                    'custom',
                    data.custom.filter((_, at) => at !== index),
                )
            }
        >
            <div className="flex flex-col gap-2 sm:flex-row">
                <div className="min-w-0 flex-1">
                    <Input
                        value={row.name}
                        onChange={(event) =>
                            setRow(index, { name: event.target.value })
                        }
                        placeholder="Department name"
                        aria-label={`Department ${index + 1} name`}
                        className="bg-background font-medium"
                    />
                    <InputError
                        message={
                            errors[
                                `custom.${index}.name` as keyof typeof errors
                            ]
                        }
                        className="mt-1"
                    />
                </div>
                <div className="w-full sm:w-28">
                    <Input
                        value={row.code}
                        onChange={(event) =>
                            setRow(index, {
                                code: event.target.value.toUpperCase(),
                            })
                        }
                        placeholder="Code"
                        aria-label={`Department ${index + 1} code`}
                        className="bg-background font-mono text-xs uppercase"
                    />
                    <InputError
                        message={
                            errors[
                                `custom.${index}.code` as keyof typeof errors
                            ]
                        }
                        className="mt-1"
                    />
                </div>
            </div>

            <Input
                value={row.description}
                onChange={(event) =>
                    setRow(index, { description: event.target.value })
                }
                placeholder="What this department does (optional)"
                aria-label={`Department ${index + 1} description`}
                className="mt-2 bg-background"
            />
        </CustomRow>
    ));

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <StepBody>
                <SuggestionsPanel
                    open={open}
                    onOpenChange={setOpen}
                    collapsible={controls.configured}
                    description={
                        <>
                            Pick the ones your company has. Call one something
                            else with{' '}
                            <span className="text-foreground">Customise</span>,
                            or name your own — then nest them and add positions
                            below.
                        </>
                    }
                    summary={
                        remaining === 0
                            ? 'Name a department of your own'
                            : `${remaining} common ${remaining === 1 ? 'department' : 'departments'} you haven't added, or name your own`
                    }
                    onSubmit={() => submit(false)}
                    submitLabel={total === 0 ? 'Add departments' : addLabel}
                    submitDisabled={total === 0}
                    processing={processing && !continuing}
                >
                    <div className="grid gap-2.5 sm:grid-cols-2">
                        {blueprints.map((blueprint) => {
                            const already = taken.has(
                                blueprint.name.toLowerCase(),
                            );
                            const moved = customised.has(blueprint.code);

                            return (
                                <ChoiceCard
                                    key={blueprint.code}
                                    mode="multiple"
                                    name="departments"
                                    value={blueprint.code}
                                    checked={data.codes.includes(
                                        blueprint.code,
                                    )}
                                    onChange={(checked) =>
                                        toggle(blueprint.code, checked)
                                    }
                                    disabled={already || moved}
                                    title={blueprint.name}
                                    description={blueprint.description}
                                    aside={
                                        already ? (
                                            'Already added'
                                        ) : moved ? (
                                            'In your list'
                                        ) : (
                                            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] tracking-wide">
                                                {blueprint.code}
                                            </code>
                                        )
                                    }
                                    action={
                                        already || moved ? undefined : (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    customise(blueprint)
                                                }
                                                className="ml-7 text-[11px] font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                            >
                                                Customise
                                            </button>
                                        )
                                    }
                                />
                            );
                        })}
                    </div>

                    <InputError message={errors.codes} />

                    <CustomSection
                        title="Departments you name yourself"
                        hint="Anything the list above doesn't cover, and anything you customised. A short code goes on reports and exports — leave it blank and we'll make one from the name."
                        addLabel="Add a department"
                        empty="Nothing here yet. Everything you add becomes a real department you can nest, staff and post jobs against."
                        onAdd={() =>
                            addRow({
                                name: '',
                                code: '',
                                description: '',
                                source: null,
                            })
                        }
                    >
                        {rows.length > 0 ? rows : undefined}
                    </CustomSection>
                </SuggestionsPanel>

                <SectionHeading
                    title="Your departments"
                    hint="Open a department to add the positions under it. Edit one to nest it under another, or to set the schedule and attendance policy its people default to. Heads can be named once people have joined."
                />

                <DepartmentsManager {...screen} showStats={false} />
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
                        : 'Add a department, or skip this step for now.'
                }
            />
        </div>
    );
}

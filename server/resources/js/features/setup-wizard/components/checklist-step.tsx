import { useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { StepControls } from '../types';
import ChoiceCard from './choice-card';
import StepBody from './step-body';
import StepFooter, { continueAction } from './step-footer';
import SuggestionsPanel, { useSuggestionsOpen } from './suggestions-panel';

/** A checklist on offer, with each of its lines already worded for the card. */
export type ChecklistOffer = {
    key: string;
    name: string;
    description: string;
    lines: { label: string; meta: string }[];
};

type Props = StepControls & {
    offers: ChecklistOffer[];
    /** Names the company's checklists already go by. */
    existing: string[];
    /** Where adopting one posts. */
    action: string;
    /** "checklist", "clearance" — what one of these is called on this step. */
    noun: string;
    description: ReactNode;
    /** Under the lines — how they can be changed afterwards. */
    footnote: ReactNode;
    /** The step's Company Setup editor, under its heading. */
    children: ReactNode;
};

/**
 * Onboarding and Offboarding: adopt the checklist most companies start with —
 * every line shown, so what is being adopted is plain — under the company's own
 * name for it if it likes, then change any line in the program editor below.
 *
 * A checklist is adopted whole rather than line by line here: the editor under
 * it is where lines are added, dropped, reordered and re-routed, and doing that
 * twice — once in a tray, once in the editor — would be one editor too many.
 */
export default function ChecklistStep({
    offers,
    existing,
    action,
    noun,
    description,
    footnote,
    children,
    ...controls
}: Props) {
    const [open, setOpen] = useSuggestionsOpen(controls.configured);
    // Which button started the save — the tray's (add, and stay) or the
    // footer's (add, and continue) — so only that one spins.
    const [continuing, setContinuing] = useState(false);
    const taken = new Set(existing.map((name) => name.toLowerCase()));

    const firstFree =
        offers.find((offer) => !taken.has(offer.name.toLowerCase())) ??
        offers[0];

    const { data, setData, post, processing, errors, clearErrors, reset } =
        useForm({
            blueprint: firstFree?.key ?? '',
            name: '',
        });

    const chosen = offers.find((offer) => offer.key === data.blueprint);
    const name = data.name.trim() || chosen?.name || '';
    const nameTaken = taken.has(name.toLowerCase());
    const ready = chosen !== undefined && !nameTaken;

    /** Adopt the checklist — then stay to edit it, or move on. */
    const submit = (andContinue: boolean) => {
        setContinuing(andContinue);

        post(action, {
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

    const pending = open && ready;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <StepBody>
                <SuggestionsPanel
                    open={open}
                    onOpenChange={setOpen}
                    collapsible={controls.configured}
                    description={description}
                    summary={`A standard ${noun} to start from, if you want another`}
                    onSubmit={() => submit(false)}
                    submitLabel={name ? `Create "${name}"` : `Create ${noun}`}
                    submitDisabled={!ready}
                    processing={processing && !continuing}
                    note={
                        nameTaken
                            ? `You already have one called "${name}" — give this one another name.`
                            : undefined
                    }
                >
                    <div className="flex flex-col gap-2.5">
                        {offers.map((offer) => (
                            <ChoiceCard
                                key={offer.key}
                                mode="single"
                                name={`${noun}-offer`}
                                value={offer.key}
                                checked={data.blueprint === offer.key}
                                onChange={() => {
                                    clearErrors('blueprint');
                                    setData('blueprint', offer.key);
                                }}
                                title={offer.name}
                                description={offer.description}
                                aside={`${offer.lines.length} items`}
                            >
                                <ol className="mt-1 grid gap-x-6 gap-y-1.5 pl-7 sm:grid-cols-2">
                                    {offer.lines.map((line) => (
                                        <li
                                            key={line.label}
                                            className="flex items-start gap-1.5 text-[11px] leading-snug"
                                        >
                                            <Check
                                                aria-hidden
                                                className="mt-px size-3 shrink-0 text-[#0a8b91] dark:text-[#0ABFBF]"
                                            />
                                            <span className="min-w-0">
                                                <span className="text-foreground/85">
                                                    {line.label}
                                                </span>
                                                <span className="block text-muted-foreground">
                                                    {line.meta}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            </ChoiceCard>
                        ))}
                    </div>

                    <InputError message={errors.blueprint} />

                    <div className="max-w-sm">
                        <Label
                            htmlFor={`${noun}-name`}
                            className="mb-1.5 block"
                        >
                            Call it something else{' '}
                            <span className="text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id={`${noun}-name`}
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder={chosen?.name}
                            className="bg-background"
                        />
                        <InputError message={errors.name} className="mt-1.5" />
                    </div>

                    <p className="text-xs leading-relaxed text-muted-foreground">
                        {footnote}
                    </p>
                </SuggestionsPanel>

                {children}
            </StepBody>

            <StepFooter
                onBack={controls.onBack}
                onSkip={controls.onSkip}
                busy={controls.busy}
                skipping={controls.skipping}
                primary={
                    pending
                        ? {
                              label: `Create "${name}" and continue`,
                              onClick: () => submit(true),
                              processing: processing && continuing,
                              disabled: processing,
                          }
                        : continueAction(controls)
                }
                note={
                    pending || controls.configured
                        ? undefined
                        : `Create a ${noun}, or skip this step for now.`
                }
            />
        </div>
    );
}

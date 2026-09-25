import { useForm } from '@inertiajs/react';
import { Repeat } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { HolidayTypeBadge } from '@/features/schedule-config/components/holiday-type-badge';
import { ScheduleManager } from '@/features/schedule-config/components/schedule-manager';
import type { ScheduleSetupPageProps } from '@/features/schedule-config/types';
import { setupWizardRoutes } from '../routes';
import type { HolidayBlueprint, StepControls } from '../types';
import ChoiceCard from './choice-card';
import SectionHeading from './section-heading';
import StepBody from './step-body';
import StepFooter, { continueAction } from './step-footer';
import SuggestionsPanel from './suggestions-panel';

type Props = StepControls & {
    screen: ScheduleSetupPageProps;
    blueprints: HolidayBlueprint[];
    existing: string[];
};

/**
 * When people work, and when nobody does: every work schedule — fixed,
 * flexible or hours-only, weekly or rotating, the company default among them —
 * and the holiday calendar, in the same editor Company Setup uses.
 *
 * What the step adds is the calendar a Philippine employer keeps anyway. Every
 * holiday on it is ticked, because they are set by law rather than chosen; the
 * fixed ones are kept every year from one row, and the movable ones (Holy Week,
 * National Heroes Day) are offered on their next date. A holiday is never
 * charged as leave and is recorded as a holiday by attendance, so this is worth
 * doing before the first leave request is filed.
 */
export default function ScheduleStep({
    screen,
    blueprints,
    existing,
    ...controls
}: Props) {
    const taken = new Set(existing.map((name) => name.toLowerCase()));
    const offered = blueprints.filter(
        (blueprint) => !taken.has(blueprint.name.toLowerCase()),
    );

    // The calendar is what this step offers, so it starts open while the
    // company has no holidays — whatever its schedules.
    const [open, setOpen] = useState(screen.holidays.length === 0);
    // Which button started the save — the tray's (add, and stay) or the
    // footer's (add, and continue) — so only that one spins.
    const [continuing, setContinuing] = useState(false);

    const { data, setData, post, processing, errors, clearErrors } = useForm({
        keys: offered.map((blueprint) => blueprint.key),
    });

    const toggle = (key: string, checked: boolean) => {
        clearErrors('keys');

        setData(
            'keys',
            checked
                ? [...data.keys, key]
                : data.keys.filter((value) => value !== key),
        );
    };

    const picked = data.keys.filter((key) =>
        offered.some((blueprint) => blueprint.key === key),
    ).length;

    /** Add the ticked holidays — then stay to adjust them, or move on. */
    const submit = (andContinue: boolean) => {
        setContinuing(andContinue);

        post(setupWizardRoutes.holidays, {
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

    const pending = open && picked > 0;
    const addLabel = `Add ${picked} ${picked === 1 ? 'holiday' : 'holidays'}`;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <StepBody>
                <SuggestionsPanel
                    open={open}
                    onOpenChange={setOpen}
                    collapsible={screen.holidays.length > 0}
                    title="Add the Philippine holiday calendar"
                    description="The regular and special non-working days set by law, ticked. Days proclaimed each year — Eid’l Fitr, Eid’l Adha, Chinese New Year, or a holiday moved by proclamation — are yours to add below once they are announced."
                    summary={
                        offered.length === 0
                            ? 'Every holiday on the calendar is already added'
                            : `${offered.length} ${offered.length === 1 ? 'holiday' : 'holidays'} on the Philippine calendar you haven't added`
                    }
                    onSubmit={() => submit(false)}
                    submitLabel={picked === 0 ? 'Add holidays' : addLabel}
                    submitDisabled={picked === 0}
                    processing={processing && !continuing}
                >
                    {offered.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-sidebar-border bg-background/60 px-4 py-5 text-center text-xs text-muted-foreground">
                            Every holiday on the calendar is already on yours.
                        </p>
                    ) : (
                        <ul className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                            {offered.map((holiday) => (
                                <li
                                    key={holiday.key}
                                    className="border-b border-sidebar-border/70 px-4 py-2.5 transition-colors last:border-b-0 has-[:checked]:bg-[#0ABFBF]/[0.05] dark:border-sidebar-border"
                                >
                                    <ChoiceCard
                                        mode="multiple"
                                        name="holidays"
                                        value={holiday.key}
                                        checked={data.keys.includes(
                                            holiday.key,
                                        )}
                                        onChange={(checked) =>
                                            toggle(holiday.key, checked)
                                        }
                                        bare
                                        title={
                                            <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <span className="w-24 shrink-0 font-normal text-muted-foreground tabular-nums">
                                                    {formatDay(holiday)}
                                                </span>
                                                {holiday.name}
                                                <HolidayTypeBadge
                                                    type={holiday.type}
                                                />
                                            </span>
                                        }
                                        aside={
                                            holiday.is_recurring ? (
                                                <span className="inline-flex items-center gap-1">
                                                    <Repeat className="size-3" />
                                                    Every year
                                                </span>
                                            ) : (
                                                'This date only'
                                            )
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    <InputError message={errors.keys} />
                </SuggestionsPanel>

                <SectionHeading
                    title="Your schedules and holidays"
                    hint="Add night, split or rotating shifts, star the schedule everyone works unless told otherwise, and keep the holiday calendar up to date."
                />

                <ScheduleManager {...screen} />
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
                        : 'Add a schedule or a holiday, or skip this step for now.'
                }
            />
        </div>
    );
}

/** "Jan 1" for a holiday kept every year; "Mar 25, 2027" for a movable one. */
function formatDay(holiday: HolidayBlueprint): string {
    const [year, month, day] = holiday.date.split('-').map(Number);
    const date = new Date(year, month - 1, day);

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        ...(holiday.is_recurring ? {} : { year: 'numeric' }),
    }).format(date);
}

import {
    AlarmClock,
    Coffee,
    Hourglass,
    Moon,
    ScanFace,
    Scissors,
    Timer,
    TimerOff,
    TrendingUp,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { formatDuration } from '@/features/attendance/constants';
import { cn } from '@/lib/utils';
import {
    GEOFENCE_OPTIONS,
    groupSummaries,
    MISSING_CLOCK_OUT_OPTIONS,
    OVERTIME_BASIS_OPTIONS,
    ROUNDING_MODE_OPTIONS,
    ROUNDING_TARGET_OPTIONS,
    ROUNDING_UNITS,
    SOURCE_LABELS,
} from '../constants';
import type { PolicySettings, PunchSource } from '../types';

type Group = keyof PolicySettings;

type Props = {
    settings: PolicySettings;
    onChange: <G extends Group>(
        group: G,
        patch: Partial<PolicySettings[G]>,
    ) => void;
    /** Inertia's flat error bag — keys like `settings.lateness.grace_minutes`. */
    errors?: Record<string, string>;
    sources: PunchSource[];
    /** The groups open when the editor appears. */
    defaultOpen?: Group[];
};

/**
 * The policy's settings, grouped the way HR thinks about a day: when somebody is
 * late, when they are short, how times are rounded, breaks, overtime, night
 * work — then the rules about capturing punches (ADR 0038).
 *
 * Each group is collapsible, and its header always says what the group amounts
 * to, so a closed group is still readable and the editor can open with only the
 * groups that matter to a first look.
 */
export function PolicySections({
    settings: s,
    onChange,
    errors = {},
    sources,
    defaultOpen = ['lateness', 'overtime'],
}: Props) {
    const summaries = groupSummaries(s);
    const error = (group: Group, key: string) =>
        errors[`settings.${group}.${key}`];
    const groupHasError = (group: Group) =>
        Object.keys(errors).some((key) => key.startsWith(`settings.${group}.`));

    const section = (group: Group, icon: LucideIcon, title: string) => ({
        group,
        icon,
        title,
        summary: summaries[group],
        defaultOpen: defaultOpen.includes(group) || groupHasError(group),
    });

    return (
        <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Section {...section('lateness', AlarmClock, 'Lateness')}>
                <ToggleRow
                    label="Judge arrival times"
                    hint="Off for teams that keep their own hours — nobody is marked late."
                    checked={s.lateness.enabled}
                    onChange={(enabled) => onChange('lateness', { enabled })}
                />

                {s.lateness.enabled && (
                    <>
                        <Choice
                            label="Grace"
                            value={s.lateness.grace_mode}
                            options={[
                                { value: 'per_day', label: 'Each day' },
                                {
                                    value: 'monthly_allowance',
                                    label: 'A monthly allowance',
                                },
                            ]}
                            onChange={(grace_mode) =>
                                onChange('lateness', { grace_mode })
                            }
                        />

                        {s.lateness.grace_mode === 'per_day' ? (
                            <OptionalMinutes
                                label="Minutes forgiven each day"
                                offLabel="Use each schedule’s own grace"
                                value={s.lateness.grace_minutes}
                                fallback={10}
                                max={240}
                                onChange={(grace_minutes) =>
                                    onChange('lateness', { grace_minutes })
                                }
                                error={error('lateness', 'grace_minutes')}
                            />
                        ) : (
                            <Minutes
                                label="Minutes forgiven across the month"
                                hint="Lateness is taken out of this until it runs out; the day it does is the first that counts."
                                value={s.lateness.monthly_grace_minutes}
                                max={1440}
                                onChange={(monthly_grace_minutes) =>
                                    onChange('lateness', {
                                        monthly_grace_minutes,
                                    })
                                }
                                error={error(
                                    'lateness',
                                    'monthly_grace_minutes',
                                )}
                            />
                        )}

                        <OptionalMinutes
                            label="A half day when later than"
                            offLabel="Never a half day for lateness"
                            value={s.lateness.half_day_after_minutes}
                            fallback={120}
                            max={1440}
                            onChange={(half_day_after_minutes) =>
                                onChange('lateness', { half_day_after_minutes })
                            }
                            error={error('lateness', 'half_day_after_minutes')}
                        />
                        <OptionalMinutes
                            label="Absent when later than"
                            offLabel="Never absent for lateness"
                            value={s.lateness.absent_after_minutes}
                            fallback={240}
                            max={1440}
                            onChange={(absent_after_minutes) =>
                                onChange('lateness', { absent_after_minutes })
                            }
                            error={error('lateness', 'absent_after_minutes')}
                        />
                    </>
                )}
            </Section>

            <Section {...section('undertime', TimerOff, 'Short days')}>
                <Choice
                    label="A day is short when"
                    value={s.undertime.basis}
                    options={[
                        {
                            value: 'schedule',
                            label: 'They leave before the shift ends',
                        },
                        {
                            value: 'hours',
                            label: 'The hours fall short',
                        },
                    ]}
                    onChange={(basis) => onChange('undertime', { basis })}
                />
                <OptionalMinutes
                    label="A half day when fewer worked than"
                    offLabel="Never a half day for a short day"
                    value={s.undertime.half_day_below_minutes}
                    fallback={240}
                    max={1440}
                    onChange={(half_day_below_minutes) =>
                        onChange('undertime', { half_day_below_minutes })
                    }
                    error={error('undertime', 'half_day_below_minutes')}
                />
                <OptionalMinutes
                    label="Absent when fewer worked than"
                    offLabel="Any time on the clock counts as present"
                    value={s.undertime.minimum_minutes_for_present}
                    fallback={60}
                    max={1440}
                    onChange={(minimum_minutes_for_present) =>
                        onChange('undertime', { minimum_minutes_for_present })
                    }
                    error={error('undertime', 'minimum_minutes_for_present')}
                />
            </Section>

            <Section {...section('rounding', Scissors, 'Rounding')}>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    Rounds the times a day is judged by. What was punched is
                    kept exactly as it was.
                </p>
                <Choice
                    label="Round"
                    value={s.rounding.mode}
                    options={ROUNDING_MODE_OPTIONS}
                    onChange={(mode) => onChange('rounding', { mode })}
                />
                {s.rounding.mode !== 'none' && (
                    <>
                        <Choice
                            label="To the"
                            value={String(s.rounding.unit)}
                            options={ROUNDING_UNITS.map((unit) => ({
                                value: String(unit),
                                label: `${unit} min`,
                            }))}
                            onChange={(unit) =>
                                onChange('rounding', {
                                    unit: Number(
                                        unit,
                                    ) as PolicySettings['rounding']['unit'],
                                })
                            }
                        />
                        <Choice
                            label="Applied to"
                            value={s.rounding.apply_to}
                            options={ROUNDING_TARGET_OPTIONS}
                            onChange={(apply_to) =>
                                onChange('rounding', { apply_to })
                            }
                        />
                    </>
                )}
            </Section>

            <Section {...section('breaks', Coffee, 'Breaks')}>
                <Minutes
                    label="Unpaid break taken off when none is punched"
                    hint="0 counts an unpunched lunch as work."
                    value={s.breaks.auto_deduct_minutes}
                    max={480}
                    onChange={(auto_deduct_minutes) =>
                        onChange('breaks', { auto_deduct_minutes })
                    }
                    error={error('breaks', 'auto_deduct_minutes')}
                />
                {s.breaks.auto_deduct_minutes > 0 && (
                    <Minutes
                        label="…once the day has run at least"
                        value={s.breaks.auto_deduct_after_worked_minutes}
                        max={1440}
                        onChange={(auto_deduct_after_worked_minutes) =>
                            onChange('breaks', {
                                auto_deduct_after_worked_minutes,
                            })
                        }
                        error={error(
                            'breaks',
                            'auto_deduct_after_worked_minutes',
                        )}
                    />
                )}
                <Minutes
                    label="Paid part of a punched break"
                    value={s.breaks.paid_break_minutes}
                    max={480}
                    onChange={(paid_break_minutes) =>
                        onChange('breaks', { paid_break_minutes })
                    }
                    error={error('breaks', 'paid_break_minutes')}
                />
                <OptionalMinutes
                    label="Longest break before it runs over"
                    offLabel="Breaks can be as long as they are"
                    hint="Time beyond it is owed like leaving early."
                    value={s.breaks.max_break_minutes}
                    fallback={60}
                    max={480}
                    onChange={(max_break_minutes) =>
                        onChange('breaks', { max_break_minutes })
                    }
                    error={error('breaks', 'max_break_minutes')}
                />
            </Section>

            <Section {...section('overtime', TrendingUp, 'Overtime')}>
                <Choice
                    label="Counted"
                    value={s.overtime.basis}
                    options={OVERTIME_BASIS_OPTIONS}
                    onChange={(basis) => onChange('overtime', { basis })}
                />
                {s.overtime.basis !== 'none' && (
                    <>
                        {s.overtime.basis !== 'weekly' && (
                            <OptionalMinutes
                                label="After this much in a day"
                                offLabel="After the day’s required hours"
                                value={s.overtime.daily_after_minutes}
                                fallback={480}
                                max={1440}
                                onChange={(daily_after_minutes) =>
                                    onChange('overtime', {
                                        daily_after_minutes,
                                    })
                                }
                                error={error('overtime', 'daily_after_minutes')}
                            />
                        )}
                        {s.overtime.basis !== 'daily' && (
                            <Minutes
                                label="After this much in a week (Mon–Sun)"
                                value={s.overtime.weekly_after_minutes}
                                max={10080}
                                step={60}
                                onChange={(weekly_after_minutes) =>
                                    onChange('overtime', {
                                        weekly_after_minutes,
                                    })
                                }
                                error={error(
                                    'overtime',
                                    'weekly_after_minutes',
                                )}
                            />
                        )}
                        <Minutes
                            label="Smallest amount that counts"
                            hint="Less than this in a day is not overtime at all."
                            value={s.overtime.min_block_minutes}
                            max={480}
                            onChange={(min_block_minutes) =>
                                onChange('overtime', { min_block_minutes })
                            }
                            error={error('overtime', 'min_block_minutes')}
                        />
                        <ToggleRow
                            label="Needs approval"
                            hint="Overtime is worked out but not approved until someone signs it off."
                            checked={s.overtime.requires_approval}
                            onChange={(requires_approval) =>
                                onChange('overtime', { requires_approval })
                            }
                        />
                        <ToggleRow
                            label="Count time before the shift starts"
                            hint="Off: clocking in early on a fixed shift earns nothing until the start."
                            checked={s.overtime.count_early_clock_in}
                            onChange={(count_early_clock_in) =>
                                onChange('overtime', { count_early_clock_in })
                            }
                        />
                        <ToggleRow
                            label="All of a rest day is overtime"
                            checked={s.overtime.rest_day_all_overtime}
                            onChange={(rest_day_all_overtime) =>
                                onChange('overtime', { rest_day_all_overtime })
                            }
                        />
                        <ToggleRow
                            label="All of a holiday is overtime"
                            checked={s.overtime.holiday_all_overtime}
                            onChange={(holiday_all_overtime) =>
                                onChange('overtime', { holiday_all_overtime })
                            }
                        />
                    </>
                )}
            </Section>

            <Section {...section('night', Moon, 'Night differential')}>
                <ToggleRow
                    label="Set night work apart"
                    hint="Minutes worked inside the window are bucketed for payroll."
                    checked={s.night.enabled}
                    onChange={(enabled) => onChange('night', { enabled })}
                />
                {s.night.enabled && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="w-full text-sm sm:w-56">Between</span>
                        <Input
                            type="time"
                            value={s.night.start}
                            onChange={(event) =>
                                onChange('night', { start: event.target.value })
                            }
                            aria-label="Night window starts"
                            className="w-28"
                        />
                        <span className="text-muted-foreground">and</span>
                        <Input
                            type="time"
                            value={s.night.end}
                            onChange={(event) =>
                                onChange('night', { end: event.target.value })
                            }
                            aria-label="Night window ends"
                            className="w-28"
                        />
                        <InputError
                            message={error('night', 'end')}
                            className="w-full"
                        />
                    </div>
                )}
            </Section>

            <Section
                {...section('punch_windows', Hourglass, 'When punches count')}
            >
                <Minutes
                    label="Clock in this early and it counts for the shift"
                    value={s.punch_windows.early_clock_in_minutes}
                    max={720}
                    step={15}
                    onChange={(early_clock_in_minutes) =>
                        onChange('punch_windows', { early_clock_in_minutes })
                    }
                    error={error('punch_windows', 'early_clock_in_minutes')}
                />
                <Minutes
                    label="Longest a shift runs before a new day starts"
                    hint="Long enough for a double shift; short enough that a forgotten clock-out does not swallow tomorrow."
                    value={s.punch_windows.max_shift_span_minutes}
                    min={240}
                    max={1440}
                    step={30}
                    onChange={(max_shift_span_minutes) =>
                        onChange('punch_windows', { max_shift_span_minutes })
                    }
                    error={error('punch_windows', 'max_shift_span_minutes')}
                />
            </Section>

            <Section
                {...section(
                    'missing_clock_out',
                    Timer,
                    'A forgotten clock-out',
                )}
            >
                <Pending>
                    Saved now; applied once the end-of-day job closes days.
                </Pending>
                <Choice
                    label="When nobody clocks out"
                    value={s.missing_clock_out.action}
                    options={MISSING_CLOCK_OUT_OPTIONS}
                    onChange={(action) =>
                        onChange('missing_clock_out', { action })
                    }
                    stacked
                />
                {s.missing_clock_out.action === 'auto_close_after_minutes' && (
                    <Minutes
                        label="How long after the shift"
                        value={s.missing_clock_out.after_minutes}
                        max={1440}
                        step={15}
                        onChange={(after_minutes) =>
                            onChange('missing_clock_out', { after_minutes })
                        }
                        error={error('missing_clock_out', 'after_minutes')}
                    />
                )}
            </Section>

            <Section {...section('capture', ScanFace, 'How people punch')}>
                <Pending>
                    Saved now; enforced once punch capture rules are switched
                    on.
                </Pending>
                <div className="space-y-2">
                    <span className="text-sm">Ways to punch</span>
                    <div className="flex flex-wrap gap-1.5">
                        {sources.map((source) => {
                            const on =
                                s.capture.allowed_sources.includes(source);

                            return (
                                <button
                                    key={source}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() =>
                                        onChange('capture', {
                                            allowed_sources: on
                                                ? s.capture.allowed_sources.filter(
                                                      (item) => item !== source,
                                                  )
                                                : [
                                                      ...s.capture
                                                          .allowed_sources,
                                                      source,
                                                  ],
                                        })
                                    }
                                    className={cn(
                                        'rounded-full border px-2.5 py-1 text-xs transition-colors',
                                        on
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 font-medium text-[#0a8b91] dark:text-[#0ABFBF]'
                                            : 'border-input text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    {SOURCE_LABELS[source]}
                                </button>
                            );
                        })}
                    </div>
                    <InputError message={error('capture', 'allowed_sources')} />
                </div>
                <ToggleRow
                    label="A selfie with every mobile punch"
                    checked={s.capture.selfie_required}
                    onChange={(selfie_required) =>
                        onChange('capture', { selfie_required })
                    }
                />
                <Choice
                    label="Where they punch from"
                    value={s.capture.geofence}
                    options={GEOFENCE_OPTIONS}
                    onChange={(geofence) => onChange('capture', { geofence })}
                    stacked
                />
                <IpAllowlist
                    value={s.capture.web_ip_allowlist}
                    onChange={(web_ip_allowlist) =>
                        onChange('capture', { web_ip_allowlist })
                    }
                    errors={errors}
                />
            </Section>
        </div>
    );
}

/** One collapsible group, its header saying what the group amounts to. */
function Section({
    icon: Icon,
    title,
    summary,
    defaultOpen,
    children,
}: {
    group: Group;
    icon: LucideIcon;
    title: string;
    summary: string;
    defaultOpen: boolean;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none">
                <Icon className="size-4 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium">{title}</span>
                    {!open && (
                        <span className="block truncate text-xs text-muted-foreground">
                            {summary}
                        </span>
                    )}
                </span>
                <span
                    aria-hidden
                    className={cn(
                        'text-xs text-muted-foreground transition-transform',
                        open && 'rotate-90',
                    )}
                >
                    ›
                </span>
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-4 px-4 pt-1 pb-4 sm:pl-11">
                {children}
            </CollapsibleContent>
        </Collapsible>
    );
}

/** A labelled switch. */
function ToggleRow({
    label,
    hint,
    checked,
    onChange,
}: {
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const id = useId();

    return (
        <div className="flex items-start justify-between gap-4">
            <label htmlFor={id} className="min-w-0 cursor-pointer">
                <span className="block text-sm">{label}</span>
                {hint && (
                    <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                        {hint}
                    </span>
                )}
            </label>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

/** A small set of mutually exclusive choices, laid out as a segmented row. */
function Choice<T extends string>({
    label,
    value,
    options,
    onChange,
    stacked = false,
}: {
    label: string;
    value: T;
    options: { value: T; label: string; hint?: string }[];
    onChange: (value: T) => void;
    stacked?: boolean;
}) {
    const hint = options.find((option) => option.value === value)?.hint;

    return (
        <div
            role="radiogroup"
            aria-label={label}
            className="flex flex-col gap-2 sm:flex-row sm:items-start"
        >
            <span className="text-sm sm:w-56 sm:shrink-0 sm:pt-1.5">
                {label}
            </span>
            <div className="min-w-0 flex-1">
                <div
                    className={cn(
                        'flex gap-1 rounded-lg bg-muted/60 p-1',
                        stacked ? 'flex-col' : 'flex-wrap',
                    )}
                >
                    {options.map((option) => {
                        const active = option.value === value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={active}
                                onClick={() => onChange(option.value)}
                                className={cn(
                                    'rounded-md px-2.5 py-1.5 text-left text-xs transition-colors',
                                    active
                                        ? 'bg-background font-medium text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {option.label}
                            </button>
                        );
                    })}
                </div>
                {hint && (
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {hint}
                    </p>
                )}
            </div>
        </div>
    );
}

/** A minute count, with what it reads as in hours beside it. */
function Minutes({
    label,
    hint,
    value,
    onChange,
    min = 0,
    max,
    step = 5,
    error,
}: {
    label: string;
    hint?: string;
    value: number;
    onChange: (value: number) => void;
    min?: number;
    max: number;
    step?: number;
    error?: string;
}) {
    const id = useId();

    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
            <label
                htmlFor={id}
                className="text-sm sm:w-56 sm:shrink-0 sm:pt-1.5"
            >
                {label}
            </label>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <Input
                        id={id}
                        type="number"
                        inputMode="numeric"
                        min={min}
                        max={max}
                        step={step}
                        value={value}
                        onChange={(event) =>
                            onChange(Math.max(0, Number(event.target.value)))
                        }
                        className="h-8 w-24"
                    />
                    <span className="text-xs text-muted-foreground tabular-nums">
                        min{value >= 60 ? ` · ${formatDuration(value)}` : ''}
                    </span>
                </div>
                {hint && (
                    <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                        {hint}
                    </p>
                )}
                <InputError message={error} className="mt-1.5" />
            </div>
        </div>
    );
}

/**
 * A threshold that can be switched off — null means "not set". Switching it on
 * starts from a sensible value rather than zero, which would mean "always".
 */
function OptionalMinutes({
    label,
    offLabel,
    hint,
    value,
    fallback,
    max,
    onChange,
    error,
}: {
    label: string;
    offLabel: string;
    hint?: string;
    value: number | null;
    fallback: number;
    max: number;
    onChange: (value: number | null) => void;
    error?: string;
}) {
    const id = useId();
    const on = value !== null;

    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
            <label
                htmlFor={id}
                className="text-sm sm:w-56 sm:shrink-0 sm:pt-1.5"
            >
                {label}
            </label>
            <div className="min-w-0 flex-1">
                <div className="flex min-h-8 flex-wrap items-center gap-2">
                    <Switch
                        id={id}
                        checked={on}
                        onCheckedChange={(checked) =>
                            onChange(checked ? fallback : null)
                        }
                        aria-label={`${label}: ${on ? 'on' : 'off'}`}
                    />
                    {on ? (
                        <>
                            <Input
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={max}
                                step={5}
                                value={value}
                                onChange={(event) =>
                                    onChange(
                                        Math.max(0, Number(event.target.value)),
                                    )
                                }
                                aria-label={label}
                                className="h-8 w-24"
                            />
                            <span className="text-xs text-muted-foreground tabular-nums">
                                min
                                {value >= 60
                                    ? ` · ${formatDuration(value)}`
                                    : ''}
                            </span>
                        </>
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            {offLabel}
                        </span>
                    )}
                </div>
                {hint && (
                    <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                        {hint}
                    </p>
                )}
                <InputError message={error} className="mt-1.5" />
            </div>
        </div>
    );
}

/** A group whose rules are recorded now and take effect later. */
function Pending({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-md bg-muted/60 px-2.5 py-2 text-xs leading-relaxed text-muted-foreground">
            {children}
        </p>
    );
}

/** Office networks a web punch may come from — one address or range per line. */
function IpAllowlist({
    value,
    onChange,
    errors,
}: {
    value: string[];
    onChange: (value: string[]) => void;
    errors: Record<string, string>;
}) {
    const id = useId();
    const [text, setText] = useState(value.join('\n'));
    const messages = Object.entries(errors)
        .filter(([key]) => key.startsWith('settings.capture.web_ip_allowlist'))
        .map(([, message]) => message);

    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
            <label
                htmlFor={id}
                className="text-sm sm:w-56 sm:shrink-0 sm:pt-1.5"
            >
                Web punches only from
            </label>
            <div className="min-w-0 flex-1">
                <textarea
                    id={id}
                    rows={2}
                    value={text}
                    onChange={(event) => {
                        setText(event.target.value);
                        onChange(
                            event.target.value
                                .split(/[\n,]/)
                                .map((line) => line.trim())
                                .filter(Boolean),
                        );
                    }}
                    placeholder="Any network"
                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
                <p className="mt-1.5 text-xs text-muted-foreground">
                    One address or range per line, such as 203.0.113.0/24. Empty
                    allows any network.
                </p>
                {messages.slice(0, 1).map((message) => (
                    <InputError key={message} message={message} />
                ))}
            </div>
        </div>
    );
}

import {
    AlarmClock,
    Coffee,
    Fingerprint,
    SlidersHorizontal,
    TimerOff,
    TrendingUp,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import {
    ROUNDING_TARGET_OPTIONS,
    ROUNDING_UNITS,
    SOURCE_DESCRIPTIONS,
    SOURCE_LABELS,
    topicSummaries,
} from '../constants';
import type { PolicySettings, PunchSource, RoundingMode } from '../types';
import {
    DurationBlank,
    NumberBlank,
    Options,
    Rule,
    SelectBlank,
    Statement,
    TimeBlank,
} from './rule-kit';

type Group = keyof PolicySettings;

export type RuleTopicKey =
    'late' | 'short' | 'breaks' | 'overtime' | 'clocking' | 'fine';

/** A topic the editor shows before the rules — the policy's name, say. */
export type ExtraTopic = {
    key: string;
    title: string;
    icon: LucideIcon;
    summary: string;
    intro?: string;
    hasError?: boolean;
    content: ReactNode;
};

type Props = {
    settings: PolicySettings;
    onChange: <G extends Group>(
        group: G,
        patch: Partial<PolicySettings[G]>,
    ) => void;
    /** Inertia's flat error bag — keys like `settings.lateness.grace_minutes`. */
    errors?: Record<string, string>;
    sources: PunchSource[];
    /** Topics shown before the rules, in the same rail. */
    before?: ExtraTopic[];
    /** The topic on screen, when the caller keeps it (to move on from a footer). */
    topic?: string;
    onTopicChange?: (topic: string) => void;
};

/**
 * Which settings each topic holds — the error keys it owns, so a topic with a
 * problem in it says so in the rail.
 */
const TOPIC_ERRORS: Record<RuleTopicKey, string[]> = {
    late: ['settings.lateness.'],
    short: ['settings.undertime.'],
    breaks: ['settings.breaks.'],
    overtime: ['settings.overtime.', 'settings.night.'],
    clocking: [
        'settings.capture.allowed_sources',
        'settings.capture.selfie_required',
        'settings.capture.geofence',
        'settings.capture.web_ip_allowlist',
        'settings.reminders.',
        'settings.missing_clock_out.',
    ],
    fine: [
        'settings.rounding.',
        'settings.punch_windows.',
        'settings.capture.offline_window_hours',
        'settings.capture.max_clock_skew_minutes',
    ],
};

export const RULE_TOPICS: {
    key: RuleTopicKey;
    title: string;
    icon: LucideIcon;
    intro: string;
}[] = [
    {
        key: 'late',
        title: 'Late arrivals',
        icon: AlarmClock,
        intro: 'When someone counts as late, and how much lateness you let go.',
    },
    {
        key: 'short',
        title: 'Short days',
        icon: TimerOff,
        intro: 'When a day comes up short, and when it counts as only half a day or not at all.',
    },
    {
        key: 'breaks',
        title: 'Breaks',
        icon: Coffee,
        intro: 'How break time comes off the hours someone worked.',
    },
    {
        key: 'overtime',
        title: 'Overtime & night work',
        icon: TrendingUp,
        intro: 'Which hours count as overtime or night work. SYNAPSE counts the minutes; your payroll pays them.',
    },
    {
        key: 'clocking',
        title: 'Clocking in',
        icon: Fingerprint,
        intro: 'How and where people may clock in, and what happens when they forget to clock out.',
    },
    {
        key: 'fine',
        title: 'Fine-tuning',
        icon: SlidersHorizontal,
        intro: 'Rarely changed. What these start at suits most companies.',
    },
];

/** The first topic, in rail order, with a problem in it — to show after a failed save. */
export function firstTopicWithError(
    errors: Record<string, string>,
    before: ExtraTopic[] = [],
): string | null {
    const extra = before.find((topic) => topic.hasError);

    if (extra) {
        return extra.key;
    }

    const keys = Object.keys(errors);
    const topic = RULE_TOPICS.find(({ key }) =>
        keys.some((error) =>
            TOPIC_ERRORS[key].some((prefix) => error.startsWith(prefix)),
        ),
    );

    return topic?.key ?? null;
}

/**
 * An attendance policy's rules (ADR 0038), written the way HR would explain them
 * to a new hire: a handful of topics in a rail, and in each one the rules as
 * sentences with the numbers filled in. The rail says what each topic amounts
 * to, so the whole policy can be read without opening anything.
 */
export function PolicySections({
    settings: s,
    onChange,
    errors = {},
    sources,
    before = [],
    topic: controlled,
    onTopicChange,
}: Props) {
    const [own, setOwn] = useState<string>(before[0]?.key ?? 'late');
    const active = controlled ?? own;
    const choose = (key: string) => {
        setOwn(key);
        onTopicChange?.(key);
    };

    const summaries = topicSummaries(s);
    const error = (group: Group, key: string) =>
        errors[`settings.${group}.${key}`];
    const errorKeys = Object.keys(errors);
    const topicHasError = (key: RuleTopicKey) =>
        errorKeys.some((error) =>
            TOPIC_ERRORS[key].some((prefix) => error.startsWith(prefix)),
        );

    const rail = [
        ...before.map((topic) => ({
            key: topic.key,
            title: topic.title,
            icon: topic.icon,
            summary: topic.summary,
            hasError: topic.hasError ?? false,
        })),
        ...RULE_TOPICS.map((topic) => ({
            key: topic.key,
            title: topic.title,
            icon: topic.icon,
            summary: summaries[topic.key],
            hasError: topicHasError(topic.key),
        })),
    ];

    const extra = before.find((topic) => topic.key === active);
    const rule = RULE_TOPICS.find((topic) => topic.key === active);
    const heading = extra ?? rule ?? RULE_TOPICS[0];

    return (
        <div className="grid min-w-0 gap-5 lg:grid-cols-[13.5rem_minmax(0,1fr)] lg:gap-7">
            <TopicRail items={rail} active={active} onChoose={choose} />

            <section aria-labelledby="policy-topic-title" className="min-w-0">
                <header className="mb-5">
                    <h3
                        id="policy-topic-title"
                        className="text-[1.0625rem] font-semibold tracking-tight"
                    >
                        {heading.title}
                    </h3>
                    {heading.intro && (
                        <p className="mt-1 max-w-prose text-sm text-muted-foreground">
                            {heading.intro}
                        </p>
                    )}
                </header>

                {extra ? (
                    extra.content
                ) : (
                    <div className="divide-y divide-border/70">
                        {(rule?.key ?? 'late') === 'late' && (
                            <LateRules
                                s={s}
                                onChange={onChange}
                                error={error}
                            />
                        )}
                        {rule?.key === 'short' && (
                            <ShortRules
                                s={s}
                                onChange={onChange}
                                error={error}
                            />
                        )}
                        {rule?.key === 'breaks' && (
                            <BreakRules
                                s={s}
                                onChange={onChange}
                                error={error}
                            />
                        )}
                        {rule?.key === 'overtime' && (
                            <OvertimeRules
                                s={s}
                                onChange={onChange}
                                error={error}
                            />
                        )}
                        {rule?.key === 'clocking' && (
                            <ClockingRules
                                s={s}
                                onChange={onChange}
                                error={error}
                                errors={errors}
                                sources={sources}
                            />
                        )}
                        {rule?.key === 'fine' && (
                            <FineRules
                                s={s}
                                onChange={onChange}
                                error={error}
                            />
                        )}
                    </div>
                )}
            </section>
        </div>
    );
}

type RulesProps = {
    s: PolicySettings;
    onChange: Props['onChange'];
    error: (group: Group, key: string) => string | undefined;
};

// ── Late arrivals ────────────────────────────────────────────────────────────

type GraceChoice = 'daily' | 'schedule' | 'monthly';

function LateRules({ s, onChange, error }: RulesProps) {
    const late = s.lateness;
    const grace: GraceChoice =
        late.grace_mode === 'monthly_allowance'
            ? 'monthly'
            : late.grace_minutes === null
              ? 'schedule'
              : 'daily';
    const lastDaily = useRemembered(late.grace_minutes, 10);

    return (
        <>
            <Rule
                checked={late.enabled}
                onCheckedChange={(enabled) => onChange('lateness', { enabled })}
                sentence="Mark people late when they clock in after their shift starts."
                offHint="Nobody is ever marked late. Suits teams that keep their own hours; short days still count."
            />

            {late.enabled && (
                <>
                    <Options<GraceChoice>
                        question="How much lateness do you let go?"
                        value={grace}
                        onChange={(choice) =>
                            onChange(
                                'lateness',
                                choice === 'monthly'
                                    ? { grace_mode: 'monthly_allowance' }
                                    : {
                                          grace_mode: 'per_day',
                                          grace_minutes:
                                              choice === 'schedule'
                                                  ? null
                                                  : lastDaily,
                                      },
                            )
                        }
                        error={
                            error('lateness', 'grace_minutes') ??
                            error('lateness', 'monthly_grace_minutes')
                        }
                        options={[
                            {
                                value: 'daily',
                                label: (chosen) => (
                                    <>
                                        The first{' '}
                                        <DurationBlank
                                            label="Lateness let go each day"
                                            units={['min']}
                                            max={240}
                                            value={
                                                late.grace_minutes ?? lastDaily
                                            }
                                            muted={!chosen}
                                            onChange={(grace_minutes) =>
                                                onChange('lateness', {
                                                    grace_mode: 'per_day',
                                                    grace_minutes,
                                                })
                                            }
                                        />{' '}
                                        each day
                                    </>
                                ),
                                hint: 'Someone 12 minutes late with 10 let go is late by 2.',
                            },
                            {
                                value: 'schedule',
                                label: 'Whatever grace each work schedule sets',
                                hint: 'Set on each schedule in Attendance → Schedules.',
                            },
                            {
                                value: 'monthly',
                                label: (chosen) => (
                                    <>
                                        Up to{' '}
                                        <DurationBlank
                                            label="Lateness let go each month"
                                            max={1440}
                                            value={late.monthly_grace_minutes}
                                            muted={!chosen}
                                            onChange={(monthly_grace_minutes) =>
                                                onChange('lateness', {
                                                    grace_mode:
                                                        'monthly_allowance',
                                                    monthly_grace_minutes,
                                                })
                                            }
                                        />{' '}
                                        in total each month
                                    </>
                                ),
                                hint: 'Lateness comes out of this until it runs out; from then on every late minute counts.',
                            },
                        ]}
                    />

                    <ThresholdRule
                        value={late.half_day_after_minutes}
                        fallback={120}
                        max={1440}
                        onChange={(half_day_after_minutes) =>
                            onChange('lateness', { half_day_after_minutes })
                        }
                        error={error('lateness', 'half_day_after_minutes')}
                        sentence={(blank) => (
                            <>
                                Count it as a half day when someone is more than{' '}
                                {blank} late.
                            </>
                        )}
                        label="Late enough for a half day"
                    />
                    <ThresholdRule
                        value={late.absent_after_minutes}
                        fallback={240}
                        max={1440}
                        onChange={(absent_after_minutes) =>
                            onChange('lateness', { absent_after_minutes })
                        }
                        error={error('lateness', 'absent_after_minutes')}
                        sentence={(blank) => (
                            <>
                                Mark them absent when they are more than {blank}{' '}
                                late.
                            </>
                        )}
                        label="Late enough to be absent"
                    />
                </>
            )}
        </>
    );
}

// ── Short days ───────────────────────────────────────────────────────────────

function ShortRules({ s, onChange, error }: RulesProps) {
    const short = s.undertime;

    return (
        <>
            <Options
                question="A day is short when someone…"
                value={short.basis}
                onChange={(basis) => onChange('undertime', { basis })}
                options={[
                    {
                        value: 'schedule',
                        label: 'leaves before their shift ends',
                        hint: 'Leaving at 4:30 on a shift that ends at 5:00 is 30 minutes short.',
                    },
                    {
                        value: 'hours',
                        label: 'works fewer hours than the shift asks for, whenever they come and go',
                        hint: 'Arriving late and staying late to make up the hours is not short.',
                    },
                ]}
            />
            <ThresholdRule
                value={short.half_day_below_minutes}
                fallback={240}
                max={1440}
                onChange={(half_day_below_minutes) =>
                    onChange('undertime', { half_day_below_minutes })
                }
                error={error('undertime', 'half_day_below_minutes')}
                sentence={(blank) => (
                    <>
                        Count it as a half day when someone works less than{' '}
                        {blank}.
                    </>
                )}
                label="Worked too little for a full day"
            />
            <ThresholdRule
                value={short.minimum_minutes_for_present}
                fallback={60}
                max={1440}
                onChange={(minimum_minutes_for_present) =>
                    onChange('undertime', { minimum_minutes_for_present })
                }
                error={error('undertime', 'minimum_minutes_for_present')}
                sentence={(blank) => (
                    <>Mark them absent when they work less than {blank}.</>
                )}
                offHint="Any time on the clock counts as being there."
                label="Worked too little to count as present"
            />
        </>
    );
}

// ── Breaks ───────────────────────────────────────────────────────────────────

function BreakRules({ s, onChange, error }: RulesProps) {
    const breaks = s.breaks;
    const lastDeduct = useRemembered(
        breaks.auto_deduct_minutes > 0 ? breaks.auto_deduct_minutes : null,
        60,
    );
    const lastPaid = useRemembered(
        breaks.paid_break_minutes > 0 ? breaks.paid_break_minutes : null,
        15,
    );
    const deducting = breaks.auto_deduct_minutes > 0;
    const paying = breaks.paid_break_minutes > 0;

    return (
        <>
            <Rule
                checked={deducting}
                onCheckedChange={(on) =>
                    onChange('breaks', {
                        auto_deduct_minutes: on ? lastDeduct : 0,
                    })
                }
                sentence={
                    <>
                        When someone doesn’t clock out for a break, take{' '}
                        <DurationBlank
                            label="Break taken off"
                            max={480}
                            value={
                                deducting
                                    ? breaks.auto_deduct_minutes
                                    : lastDeduct
                            }
                            muted={!deducting}
                            onChange={(auto_deduct_minutes) =>
                                onChange('breaks', { auto_deduct_minutes })
                            }
                        />{' '}
                        off any day longer than{' '}
                        <DurationBlank
                            label="Day long enough for a break"
                            max={1440}
                            value={breaks.auto_deduct_after_worked_minutes}
                            muted={!deducting}
                            onChange={(auto_deduct_after_worked_minutes) =>
                                onChange('breaks', {
                                    auto_deduct_after_worked_minutes,
                                })
                            }
                        />
                        .
                    </>
                }
                hint="For people who take lunch but never clock out for it."
                offHint="A lunch nobody clocked out for counts as work."
                error={
                    error('breaks', 'auto_deduct_minutes') ??
                    error('breaks', 'auto_deduct_after_worked_minutes')
                }
            />
            <Rule
                checked={paying}
                onCheckedChange={(on) =>
                    onChange('breaks', {
                        paid_break_minutes: on ? lastPaid : 0,
                    })
                }
                sentence={
                    <>
                        Pay for the first{' '}
                        <DurationBlank
                            label="Paid part of a break"
                            max={480}
                            value={
                                paying ? breaks.paid_break_minutes : lastPaid
                            }
                            muted={!paying}
                            onChange={(paid_break_minutes) =>
                                onChange('breaks', { paid_break_minutes })
                            }
                        />{' '}
                        of every break someone clocks out for.
                    </>
                }
                hint="That much of each break counts as work; the rest doesn’t."
                offHint="Breaks people clock out for are unpaid."
                error={error('breaks', 'paid_break_minutes')}
            />
            <ThresholdRule
                value={breaks.max_break_minutes}
                fallback={60}
                max={480}
                onChange={(max_break_minutes) =>
                    onChange('breaks', { max_break_minutes })
                }
                error={error('breaks', 'max_break_minutes')}
                sentence={(blank) => (
                    <>
                        Treat a break longer than {blank} as leaving early for
                        the extra time.
                    </>
                )}
                offHint="A break can run as long as it runs."
                label="Longest break"
            />
        </>
    );
}

// ── Overtime & night work ────────────────────────────────────────────────────

function OvertimeRules({ s, onChange, error }: RulesProps) {
    const ot = s.overtime;
    const lastDaily = useRemembered(ot.daily_after_minutes, 480);
    const lastBlock = useRemembered(
        ot.min_block_minutes > 0 ? ot.min_block_minutes : null,
        30,
    );
    const counting = ot.basis !== 'none';

    return (
        <>
            <Options
                question="Count overtime…"
                value={ot.basis}
                onChange={(basis) => onChange('overtime', { basis })}
                options={[
                    {
                        value: 'daily',
                        label: 'for the hours past each day’s limit',
                    },
                    {
                        value: 'weekly',
                        label: 'for the hours past the week’s limit',
                        hint: 'A long Monday and a short Tuesday balance out.',
                    },
                    {
                        value: 'daily_and_weekly',
                        label: 'both: past each day’s limit, then anything else past the week’s',
                    },
                    { value: 'none', label: 'never — no overtime is counted' },
                ]}
            />

            {counting && ot.basis !== 'weekly' && (
                <Options<'required' | 'fixed'>
                    question="A day’s overtime starts…"
                    value={
                        ot.daily_after_minutes === null ? 'required' : 'fixed'
                    }
                    onChange={(choice) =>
                        onChange('overtime', {
                            daily_after_minutes:
                                choice === 'required' ? null : lastDaily,
                        })
                    }
                    error={error('overtime', 'daily_after_minutes')}
                    options={[
                        {
                            value: 'required',
                            label: 'once the hours their shift asks for are done',
                        },
                        {
                            value: 'fixed',
                            label: (chosen) => (
                                <>
                                    after{' '}
                                    <DurationBlank
                                        label="Hours in a day before overtime"
                                        max={1440}
                                        value={
                                            ot.daily_after_minutes ?? lastDaily
                                        }
                                        muted={!chosen}
                                        onChange={(daily_after_minutes) =>
                                            onChange('overtime', {
                                                daily_after_minutes,
                                            })
                                        }
                                    />{' '}
                                    on the clock, whatever the shift
                                </>
                            ),
                        },
                    ]}
                />
            )}

            {counting && ot.basis !== 'daily' && (
                <Statement
                    sentence={
                        <>
                            A week’s overtime starts after{' '}
                            <DurationBlank
                                label="Hours in a week before overtime"
                                units={['h']}
                                max={10080}
                                value={ot.weekly_after_minutes}
                                onChange={(weekly_after_minutes) =>
                                    onChange('overtime', {
                                        weekly_after_minutes,
                                    })
                                }
                            />{' '}
                            from Monday to Sunday.
                        </>
                    }
                    error={error('overtime', 'weekly_after_minutes')}
                />
            )}

            {counting && (
                <>
                    <Rule
                        checked={ot.min_block_minutes > 0}
                        onCheckedChange={(on) =>
                            onChange('overtime', {
                                min_block_minutes: on ? lastBlock : 0,
                            })
                        }
                        sentence={
                            <>
                                Ignore overtime shorter than{' '}
                                <DurationBlank
                                    label="Least overtime that counts"
                                    max={480}
                                    value={
                                        ot.min_block_minutes > 0
                                            ? ot.min_block_minutes
                                            : lastBlock
                                    }
                                    muted={ot.min_block_minutes === 0}
                                    onChange={(min_block_minutes) =>
                                        onChange('overtime', {
                                            min_block_minutes,
                                        })
                                    }
                                />{' '}
                                in a day.
                            </>
                        }
                        offHint="Every minute past the limit counts."
                        error={error('overtime', 'min_block_minutes')}
                    />
                    <Rule
                        checked={ot.requires_approval}
                        onCheckedChange={(requires_approval) =>
                            onChange('overtime', { requires_approval })
                        }
                        sentence="Overtime must be approved before it counts."
                        hint="It’s worked out right away, then waits on the attendance board for someone to approve it."
                        offHint="Overtime counts as soon as it’s worked."
                    />
                    <Rule
                        checked={ot.count_early_clock_in}
                        onCheckedChange={(count_early_clock_in) =>
                            onChange('overtime', { count_early_clock_in })
                        }
                        sentence="Count time before the shift starts."
                        offHint="Clocking in early earns nothing until the shift starts."
                    />
                    <Rule
                        checked={ot.rest_day_all_overtime}
                        onCheckedChange={(rest_day_all_overtime) =>
                            onChange('overtime', { rest_day_all_overtime })
                        }
                        sentence="Every hour worked on a rest day is overtime."
                    />
                    <Rule
                        checked={ot.holiday_all_overtime}
                        onCheckedChange={(holiday_all_overtime) =>
                            onChange('overtime', { holiday_all_overtime })
                        }
                        sentence="Every hour worked on a holiday is overtime."
                    />
                </>
            )}

            <Rule
                checked={s.night.enabled}
                onCheckedChange={(enabled) => onChange('night', { enabled })}
                sentence={
                    <>
                        Set apart the hours worked between{' '}
                        <TimeBlank
                            label="Night hours start"
                            value={s.night.start}
                            muted={!s.night.enabled}
                            onChange={(start) => onChange('night', { start })}
                        />{' '}
                        and{' '}
                        <TimeBlank
                            label="Night hours end"
                            value={s.night.end}
                            muted={!s.night.enabled}
                            onChange={(end) => onChange('night', { end })}
                        />{' '}
                        as night work.
                    </>
                }
                hint="So payroll can add the night differential (10 PM to 6 AM under the Labor Code)."
                error={error('night', 'end') ?? error('night', 'start')}
            />
        </>
    );
}

// ── Clocking in ──────────────────────────────────────────────────────────────

function ClockingRules({
    s,
    onChange,
    error,
    errors,
    sources,
}: RulesProps & { errors: Record<string, string>; sources: PunchSource[] }) {
    const capture = s.capture;
    const allowed = capture.allowed_sources;
    const toggle = (source: PunchSource, on: boolean) =>
        onChange('capture', {
            allowed_sources: on
                ? [...allowed, source]
                : allowed.filter((item) => item !== source),
        });
    const selfServe = (
        ['mobile', 'web', 'qr', 'kiosk'] as PunchSource[]
    ).filter((source) => allowed.includes(source));
    const onlyStations =
        allowed.includes('qr') &&
        !allowed.includes('mobile') &&
        !allowed.includes('web');
    const [networks, setNetworks] = useState(
        capture.web_ip_allowlist.length > 0,
    );

    return (
        <>
            <fieldset className="py-3.5 first:pt-0">
                <legend className="mb-2 text-[0.9375rem] font-medium">
                    People may clock in…
                </legend>
                <div className="grid gap-2 sm:grid-cols-2">
                    {sources.map((source) => (
                        <SourceCard
                            key={source}
                            source={source}
                            checked={allowed.includes(source)}
                            onCheckedChange={(on) => toggle(source, on)}
                        />
                    ))}
                </div>
                {selfServe.length === 0 && (
                    <p className="mt-2 text-[0.8125rem] text-amber-700 dark:text-amber-300">
                        Nobody can clock in on their own: only HR can enter
                        times.
                    </p>
                )}
                {onlyStations && (
                    <p className="mt-2 text-[0.8125rem] text-muted-foreground">
                        With the app and web off, everyone clocks in at a QR
                        station{allowed.includes('kiosk') ? ' or a kiosk' : ''}.
                        The app’s clock button opens the scanner instead.
                    </p>
                )}
                <InputError
                    message={error('capture', 'allowed_sources')}
                    className="mt-1.5"
                />
            </fieldset>

            <Rule
                checked={capture.selfie_required}
                onCheckedChange={(selfie_required) =>
                    onChange('capture', { selfie_required })
                }
                sentence="Take a photo of the person with every app, QR and kiosk punch."
                hint="Kept with the punch, so HR can see who clocked in."
            />

            <Options
                question="Where can they clock in from?"
                value={capture.geofence}
                onChange={(geofence) => onChange('capture', { geofence })}
                options={[
                    {
                        value: 'off',
                        label: 'Anywhere — location isn’t checked',
                    },
                    {
                        value: 'flag',
                        label: 'Anywhere, but flag punches made away from your sites',
                        hint: 'A flagged day waits on the attendance board for someone to approve it.',
                    },
                    {
                        value: 'block',
                        label: 'Only at your sites — punches from elsewhere are refused',
                        hint: 'Phones, QR stations and kiosks must be inside a site. Punches that share no location are refused too.',
                    },
                ]}
            />
            {capture.geofence !== 'off' && (
                <p className="-mt-1 pb-3.5 pl-[1.8rem] text-[0.8125rem] text-muted-foreground">
                    Sites and their boundaries are set in Company Setup →
                    Locations.
                </p>
            )}

            {allowed.includes('web') && (
                <Rule
                    checked={networks}
                    onCheckedChange={(on) => {
                        setNetworks(on);

                        if (!on) {
                            onChange('capture', { web_ip_allowlist: [] });
                        }
                    }}
                    sentence="Accept web punches only from your office network."
                    offHint="A browser on any network can clock in."
                >
                    <NetworkList
                        value={capture.web_ip_allowlist}
                        onChange={(web_ip_allowlist) =>
                            onChange('capture', { web_ip_allowlist })
                        }
                        errors={errors}
                    />
                </Rule>
            )}

            <ThresholdRule
                value={s.reminders.clock_in_after_minutes}
                fallback={15}
                max={240}
                onChange={(clock_in_after_minutes) =>
                    onChange('reminders', { clock_in_after_minutes })
                }
                error={error('reminders', 'clock_in_after_minutes')}
                sentence={(blank) => (
                    <>
                        Remind people who haven’t clocked in {blank} after their
                        shift starts.
                    </>
                )}
                hint="Nobody on leave, a holiday or a rest day is reminded."
                label="Reminder after the shift starts"
            />

            <Options
                question="When someone forgets to clock out…"
                value={s.missing_clock_out.action}
                onChange={(action) => onChange('missing_clock_out', { action })}
                error={error('missing_clock_out', 'after_minutes')}
                options={[
                    {
                        value: 'flag',
                        label: 'leave the day open for HR to fix',
                    },
                    {
                        value: 'auto_close_at_shift_end',
                        label: 'clock them out when their shift ends',
                        hint: 'The clock-out SYNAPSE adds waits for someone to approve it.',
                    },
                    {
                        value: 'auto_close_after_minutes',
                        label: (chosen) => (
                            <>
                                clock them out{' '}
                                <DurationBlank
                                    label="How long after the shift"
                                    max={1440}
                                    value={s.missing_clock_out.after_minutes}
                                    muted={!chosen}
                                    onChange={(after_minutes) =>
                                        onChange('missing_clock_out', {
                                            after_minutes,
                                        })
                                    }
                                />{' '}
                                after their shift ends
                            </>
                        ),
                        hint: 'The clock-out SYNAPSE adds waits for someone to approve it.',
                    },
                ]}
            />
        </>
    );
}

function SourceCard({
    source,
    checked,
    onCheckedChange,
}: {
    source: PunchSource;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    const id = useId();

    return (
        <label
            htmlFor={id}
            className={cn(
                'flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5 transition-colors',
                checked
                    ? 'border-[#0ABFBF]/50 bg-[#0ABFBF]/[0.06]'
                    : 'border-border hover:bg-muted/50',
            )}
        >
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(value) => onCheckedChange(value === true)}
                className="mt-0.5 data-[state=checked]:border-[#0a8b91] data-[state=checked]:bg-[#0a8b91]"
            />
            <span className="min-w-0">
                <span className="block text-sm font-medium">
                    {SOURCE_LABELS[source]}
                </span>
                <span className="block text-[0.8125rem] leading-snug text-muted-foreground">
                    {SOURCE_DESCRIPTIONS[source]}
                </span>
            </span>
        </label>
    );
}

/** Office networks a web punch may come from — one address or range per line. */
function NetworkList({
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
        <div>
            <label htmlFor={id} className="mb-1.5 block text-sm">
                Your office’s internet addresses
            </label>
            <textarea
                id={id}
                rows={3}
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
                placeholder={'203.0.113.10\n198.51.100.0/24'}
                className="flex w-full max-w-sm rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
            />
            <p className="mt-1.5 text-[0.8125rem] text-muted-foreground">
                One per line. Ask your IT team, or search “what is my IP” from
                the office.{' '}
                {value.length === 0 && 'Until you add one, any network works.'}
            </p>
            {messages.slice(0, 1).map((message) => (
                <InputError key={message} message={message} />
            ))}
        </div>
    );
}

// ── Fine-tuning ──────────────────────────────────────────────────────────────

function FineRules({ s, onChange, error }: RulesProps) {
    const rounding = s.rounding;
    const unit = (
        <SelectBlank
            label="Round to"
            value={String(rounding.unit)}
            options={ROUNDING_UNITS.map((value) => ({
                value: String(value),
                label: String(value),
            }))}
            onChange={(value) =>
                onChange('rounding', {
                    unit: Number(value) as PolicySettings['rounding']['unit'],
                })
            }
        />
    );
    const roundingLabel = (words: string) =>
        function RoundingLabel(chosen: boolean) {
            return chosen ? (
                <>
                    {words} {unit} minutes
                </>
            ) : (
                <>{words} few minutes</>
            );
        };

    return (
        <>
            <Options<RoundingMode>
                question="Clock times are judged…"
                value={rounding.mode}
                onChange={(mode) => onChange('rounding', { mode })}
                options={[
                    { value: 'none', label: 'exactly as punched' },
                    {
                        value: 'nearest',
                        label: roundingLabel('to the nearest'),
                    },
                    {
                        value: 'up',
                        label: roundingLabel('rounded up to the next'),
                    },
                    {
                        value: 'down',
                        label: roundingLabel('rounded down to the last'),
                    },
                ]}
            />
            {rounding.mode !== 'none' && (
                <Statement
                    sentence={
                        <>
                            Round{' '}
                            <SelectBlank
                                label="Which punches are rounded"
                                value={rounding.apply_to}
                                options={ROUNDING_TARGET_OPTIONS}
                                onChange={(apply_to) =>
                                    onChange('rounding', { apply_to })
                                }
                            />
                            .
                        </>
                    }
                    hint="Only the judging is rounded: what people actually punched is always kept."
                />
            )}

            <Statement
                sentence={
                    <>
                        A clock-in counts toward a shift from{' '}
                        <DurationBlank
                            label="How early a clock-in counts"
                            max={720}
                            value={s.punch_windows.early_clock_in_minutes}
                            onChange={(early_clock_in_minutes) =>
                                onChange('punch_windows', {
                                    early_clock_in_minutes,
                                })
                            }
                        />{' '}
                        before it starts.
                    </>
                }
                hint="Earlier than that, it belongs to the day before."
                error={error('punch_windows', 'early_clock_in_minutes')}
            />
            <Statement
                sentence={
                    <>
                        A shift lasts at most{' '}
                        <DurationBlank
                            label="Longest shift"
                            min={240}
                            max={1440}
                            value={s.punch_windows.max_shift_span_minutes}
                            onChange={(max_shift_span_minutes) =>
                                onChange('punch_windows', {
                                    max_shift_span_minutes,
                                })
                            }
                        />
                        ; a punch after that starts a new day.
                    </>
                }
                hint="Long enough for a double shift, short enough that a forgotten clock-out doesn’t swallow the next day."
                error={error('punch_windows', 'max_shift_span_minutes')}
            />
            <Statement
                sentence={
                    <>
                        Accept punches the app saved without a connection for up
                        to{' '}
                        <NumberBlank
                            label="Oldest offline punch accepted, in hours"
                            min={1}
                            max={720}
                            step={12}
                            suffix="hours"
                            value={s.capture.offline_window_hours}
                            onChange={(offline_window_hours) =>
                                onChange('capture', { offline_window_hours })
                            }
                        />
                        .
                    </>
                }
                hint="Older ones are refused, and HR enters them instead."
                error={error('capture', 'offline_window_hours')}
            />
            <Statement
                sentence={
                    <>
                        Flag punches from a device whose clock is more than{' '}
                        <DurationBlank
                            label="How far off a device clock may be"
                            units={['min']}
                            min={1}
                            max={1440}
                            value={s.capture.max_clock_skew_minutes}
                            onChange={(max_clock_skew_minutes) =>
                                onChange('capture', { max_clock_skew_minutes })
                            }
                        />{' '}
                        off.
                    </>
                }
                hint="The punch is kept, and the day waits for someone to approve it."
                error={error('capture', 'max_clock_skew_minutes')}
            />
        </>
    );
}

// ── Shared ───────────────────────────────────────────────────────────────────

/**
 * A rule around a threshold that can be off (null): on, it reads with the
 * threshold filled in; off, the same sentence greyed with what it would be.
 */
function ThresholdRule({
    value,
    fallback,
    max,
    onChange,
    sentence,
    label,
    hint,
    offHint,
    error,
}: {
    value: number | null;
    fallback: number;
    max: number;
    onChange: (value: number | null) => void;
    sentence: (blank: ReactNode) => ReactNode;
    label: string;
    hint?: string;
    offHint?: string;
    error?: string;
}) {
    const last = useRemembered(value, fallback);
    const on = value !== null;

    return (
        <Rule
            checked={on}
            onCheckedChange={(checked) => onChange(checked ? last : null)}
            sentence={sentence(
                <DurationBlank
                    label={label}
                    max={max}
                    value={value ?? last}
                    muted={!on}
                    onChange={onChange}
                />,
            )}
            hint={hint}
            offHint={offHint}
            error={error}
        />
    );
}

/**
 * The last value a nullable setting had while it was set — so switching a rule
 * off and on again brings back what it was, not the starting value.
 */
function useRemembered(value: number | null, fallback: number): number {
    const [remembered, setRemembered] = useState(value ?? fallback);
    const previous = useRef(value);

    useEffect(() => {
        if (value !== null && value !== previous.current) {
            setRemembered(value);
        }

        previous.current = value;
    }, [value]);

    return value ?? remembered;
}

/**
 * The topics, as a rail beside the rules on a wide screen and a row of tabs
 * above them on a narrow one. Each says what it amounts to in a line, and marks
 * a problem waiting in it.
 */
function TopicRail({
    items,
    active,
    onChoose,
}: {
    items: {
        key: string;
        title: string;
        icon: LucideIcon;
        summary: string;
        hasError: boolean;
    }[];
    active: string;
    onChoose: (key: string) => void;
}) {
    const activeRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        activeRef.current?.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
        });
    }, [active]);

    return (
        <nav aria-label="Policy topics" className="min-w-0">
            <ol className="-mx-1 flex snap-x gap-1 overflow-x-auto px-1 pb-1 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0">
                {items.map((item) => {
                    const Icon = item.icon;
                    const current = item.key === active;

                    return (
                        <li key={item.key} className="snap-start">
                            <button
                                ref={current ? activeRef : undefined}
                                type="button"
                                aria-current={current ? 'step' : undefined}
                                onClick={() => onChoose(item.key)}
                                className={cn(
                                    'group relative flex w-full items-start gap-2.5 rounded-lg px-3 py-2 text-left whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none lg:whitespace-normal',
                                    current
                                        ? 'bg-[#0F2044] text-white dark:bg-[#0ABFBF]/15 dark:text-foreground'
                                        : 'text-foreground hover:bg-muted/70',
                                )}
                            >
                                <Icon
                                    className={cn(
                                        'mt-0.5 size-4 shrink-0',
                                        current
                                            ? 'text-[#0ABFBF]'
                                            : 'text-muted-foreground',
                                    )}
                                />
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium">
                                        {item.title}
                                    </span>
                                    <span
                                        className={cn(
                                            'hidden text-xs leading-snug lg:line-clamp-2',
                                            current
                                                ? 'text-white/70 dark:text-muted-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {item.summary}
                                    </span>
                                </span>
                                {item.hasError && (
                                    <span
                                        className="ml-auto size-2 shrink-0 self-center rounded-full bg-destructive"
                                        aria-label="Has a problem to fix"
                                    />
                                )}
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

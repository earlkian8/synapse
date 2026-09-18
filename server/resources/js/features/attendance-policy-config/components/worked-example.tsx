import { AlertCircle } from 'lucide-react';
import type { Dispatch, ReactNode, SetStateAction } from 'react';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    FLAG_LABELS,
    formatDuration,
    STATUS_LABELS,
    STATUS_STYLES,
} from '@/features/attendance/constants';
import type {
    AttendanceFlag,
    AttendanceStatus,
} from '@/features/attendance/types';
import { cn } from '@/lib/utils';
import { useWorkedExample } from '../hooks/use-worked-example';
import type {
    DayVerdict,
    PolicySettings,
    SampleDay,
    SampleDayKind,
} from '../types';

type Props = {
    settings: PolicySettings;
    sample: SampleDay;
    onSampleChange: Dispatch<SetStateAction<SampleDay>>;
};

const DAY_KINDS: { value: SampleDayKind; label: string }[] = [
    { value: 'working', label: 'Working day' },
    { value: 'rest_day', label: 'Rest day' },
    { value: 'holiday', label: 'Holiday' },
];

/**
 * One sample day, judged by the settings on screen before they are saved
 * (ADR 0038). The verdict comes from the server's own evaluator, so what this
 * promises is exactly what the board will do.
 *
 * The strip draws the day the way it is judged — the shift, the time on the
 * clock, a break, the night window, and what ran past the shift — and the ledger
 * under it says what the day comes out as.
 */
export function WorkedExample({ settings, sample, onSampleChange }: Props) {
    const { result, error, pending } = useWorkedExample(settings, sample);
    // Functional, so two edits in one tick (a quick tab through the fields)
    // never overwrite each other.
    const set = (patch: Partial<SampleDay>) =>
        onSampleChange((current) => ({ ...current, ...patch }));

    return (
        <section
            aria-label="Worked example"
            className="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
        >
            <header className="flex items-start justify-between gap-3 border-b border-border px-4 py-3">
                <div>
                    <h3 className="text-sm font-semibold">Worked example</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        A sample day under these rules, before you save them.
                    </p>
                </div>
                {pending && <Spinner className="mt-0.5 size-3.5" />}
            </header>

            <div className="space-y-4 px-4 py-4">
                <div
                    role="radiogroup"
                    aria-label="Kind of day"
                    className="flex gap-1 rounded-lg bg-muted/60 p-1"
                >
                    {DAY_KINDS.map((kind) => (
                        <button
                            key={kind.value}
                            type="button"
                            role="radio"
                            aria-checked={sample.day === kind.value}
                            onClick={() => set({ day: kind.value })}
                            className={cn(
                                'flex-1 rounded-md px-2 py-1 text-xs transition-colors',
                                sample.day === kind.value
                                    ? 'bg-background font-medium shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {kind.label}
                        </button>
                    ))}
                </div>

                <div className="space-y-2">
                    <TimePair
                        label={
                            sample.day === 'rest_day' ? 'Usual shift' : 'Shift'
                        }
                        start={sample.shift_start}
                        end={sample.shift_end}
                        onChange={(shift_start, shift_end) =>
                            set({ shift_start, shift_end })
                        }
                    />
                    <TimePair
                        label="Clocked"
                        start={sample.time_in}
                        end={sample.time_out}
                        onChange={(time_in, time_out) =>
                            set({ time_in, time_out })
                        }
                    />
                    <TimePair
                        label="Break"
                        start={sample.break_start}
                        end={sample.break_end}
                        onChange={(break_start, break_end) =>
                            set({ break_start, break_end })
                        }
                        optional
                    />
                    <label className="grid grid-cols-[5.5rem_minmax(0,1fr)] items-center gap-2 text-xs text-muted-foreground">
                        Schedule’s grace
                        <span className="flex items-center gap-1.5">
                            <Input
                                type="number"
                                min={0}
                                max={240}
                                inputMode="numeric"
                                value={sample.grace_minutes}
                                onChange={(event) =>
                                    set({
                                        grace_minutes: Math.max(
                                            0,
                                            Number(event.target.value),
                                        ),
                                    })
                                }
                                className="h-8 w-16"
                            />
                            min
                        </span>
                    </label>
                </div>

                <DayStrip
                    sample={sample}
                    settings={settings}
                    overtime={result?.overtime_minutes ?? 0}
                />

                {error ? (
                    <p className="flex items-start gap-2 rounded-md bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                        <AlertCircle className="mt-0.5 size-3.5 shrink-0" />
                        {error}
                    </p>
                ) : result ? (
                    <Ledger
                        result={result}
                        requiresApproval={settings.overtime.requires_approval}
                        dimmed={pending}
                    />
                ) : (
                    <p className="py-6 text-center text-xs text-muted-foreground">
                        Working it out…
                    </p>
                )}
            </div>
        </section>
    );
}

/** Two "HH:MM" inputs beside one label, a row each so 12-hour times fit. */
function TimePair({
    label,
    start,
    end,
    onChange,
    optional = false,
}: {
    label: string;
    start: string;
    end: string;
    onChange: (start: string, end: string) => void;
    optional?: boolean;
}) {
    return (
        <fieldset className="grid min-w-0 grid-cols-[5.5rem_minmax(0,1fr)] items-center gap-2">
            <legend className="sr-only">{label}</legend>
            <span aria-hidden className="text-xs text-muted-foreground">
                {label}
                {optional && (
                    <span className="block text-[10px]">optional</span>
                )}
            </span>
            <div className="flex min-w-0 items-center gap-1">
                <Input
                    type="time"
                    value={start}
                    onChange={(event) => onChange(event.target.value, end)}
                    aria-label={`${label} from`}
                    className="h-8 min-w-0 px-1.5 text-xs"
                />
                <span className="text-muted-foreground">–</span>
                <Input
                    type="time"
                    value={end}
                    onChange={(event) => onChange(start, event.target.value)}
                    aria-label={`${label} to`}
                    className="h-8 min-w-0 px-1.5 text-xs"
                />
            </div>
        </fieldset>
    );
}

/** "HH:MM" as minutes past midnight, or null when blank. */
function minuteOf(time: string): number | null {
    if (!/^\d{2}:\d{2}$/.test(time)) {
        return null;
    }

    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
}

/**
 * The sample day drawn on one axis: the shift as an outline, the time on the
 * clock filled (a break cut out of it), the night window shaded behind, and the
 * stretch past the shift's end marked when it came out as overtime. Times that
 * read earlier than the one before them are the next morning, as they are for
 * the evaluator.
 */
function DayStrip({
    sample,
    settings,
    overtime,
}: {
    sample: SampleDay;
    settings: PolicySettings;
    overtime: number;
}) {
    const chain = (times: (string | null)[]): (number | null)[] => {
        let floor: number | null = null;

        return times.map((time) => {
            let minute = time === null ? null : minuteOf(time);

            if (minute !== null && floor !== null) {
                while (minute < floor) {
                    minute += 1440;
                }
            }

            floor = minute ?? floor;

            return minute;
        });
    };

    const [shiftStart, shiftEnd] = chain([
        sample.shift_start,
        sample.shift_end,
    ]);
    // The punches are read on the sample's own date, exactly as the evaluator
    // reads them — a clock-in hours before an evening shift is before it, not
    // the next morning.
    const [clockIn, breakStart, breakEnd, clockOut] = chain([
        sample.time_in,
        sample.break_start || null,
        sample.break_end || null,
        sample.time_out || null,
    ]);

    const points = [shiftStart, shiftEnd, clockIn, clockOut].filter(
        (point): point is number => point !== null,
    );

    if (points.length === 0) {
        return null;
    }

    const from = Math.floor((Math.min(...points) - 30) / 60) * 60;
    const to = Math.ceil((Math.max(...points) + 30) / 60) * 60;
    const span = Math.max(60, to - from);
    const x = (minute: number) => `${((minute - from) / span) * 100}%`;
    const w = (a: number, b: number) =>
        `${(Math.max(0, Math.min(b, to) - Math.max(a, from)) / span) * 100}%`;

    const nights: [number, number][] = [];
    const nightStart = minuteOf(settings.night.start);
    const nightEnd = minuteOf(settings.night.end);

    if (settings.night.enabled && nightStart !== null && nightEnd !== null) {
        for (let day = Math.floor(from / 1440) - 1; day * 1440 < to; day++) {
            const start = day * 1440 + nightStart;
            const end =
                day * 1440 + nightEnd + (nightEnd <= nightStart ? 1440 : 0);

            if (end > from && start < to) {
                nights.push([Math.max(start, from), Math.min(end, to)]);
            }
        }
    }

    const ticks: number[] = [];
    const step = span > 900 ? 240 : span > 480 ? 120 : 60;

    for (let tick = Math.ceil(from / step) * step; tick <= to; tick += step) {
        ticks.push(tick);
    }

    const worked: [number, number][] =
        clockIn !== null && clockOut !== null
            ? breakStart !== null && breakEnd !== null
                ? [
                      [clockIn, breakStart],
                      [breakEnd, clockOut],
                  ]
                : [[clockIn, clockOut]]
            : [];

    const pastShift =
        overtime > 0 &&
        shiftEnd !== null &&
        clockOut !== null &&
        clockOut > shiftEnd
            ? ([Math.max(shiftEnd, clockIn ?? shiftEnd), clockOut] as const)
            : null;

    return (
        <figure aria-label="The sample day on a timeline" className="space-y-1">
            <div className="relative h-12 overflow-hidden rounded-md bg-muted/40">
                {nights.map(([start, end]) => (
                    <span
                        key={start}
                        className="absolute inset-y-0 bg-indigo-500/10 dark:bg-indigo-400/15"
                        style={{ left: x(start), width: w(start, end) }}
                    />
                ))}

                {sample.day !== 'rest_day' &&
                    shiftStart !== null &&
                    shiftEnd !== null && (
                        <span
                            className="absolute top-2 h-2 rounded-full border border-dashed border-foreground/35"
                            style={{
                                left: x(shiftStart),
                                width: w(shiftStart, shiftEnd),
                            }}
                        />
                    )}

                {worked.map(([start, end]) => (
                    <span
                        key={start}
                        className="absolute top-6 h-3.5 rounded-sm bg-[#0ABFBF]"
                        style={{ left: x(start), width: w(start, end) }}
                    />
                ))}

                {breakStart !== null && breakEnd !== null && (
                    <span
                        className="absolute top-6 h-3.5 bg-[repeating-linear-gradient(135deg,transparent_0_3px,rgb(10_191_191/0.45)_3px_5px)]"
                        style={{
                            left: x(breakStart),
                            width: w(breakStart, breakEnd),
                        }}
                    />
                )}

                {pastShift && (
                    <span
                        className="absolute top-6 h-3.5 rounded-r-sm bg-amber-500"
                        style={{
                            left: x(pastShift[0]),
                            width: w(pastShift[0], pastShift[1]),
                        }}
                    />
                )}

                {clockIn !== null && clockOut === null && (
                    <span
                        className="absolute top-6 h-3.5 w-px bg-[#0ABFBF]"
                        style={{ left: x(clockIn) }}
                    />
                )}
            </div>

            <div className="relative h-4 text-[10px] text-muted-foreground tabular-nums">
                {ticks.map((tick) => (
                    <span
                        key={tick}
                        className="absolute -translate-x-1/2"
                        style={{ left: x(tick) }}
                    >
                        {String(
                            Math.floor((((tick % 1440) + 1440) % 1440) / 60),
                        ).padStart(2, '0')}
                        :00
                    </span>
                ))}
            </div>

            <figcaption className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                <Key swatch="border border-dashed border-foreground/35">
                    Shift
                </Key>
                <Key swatch="bg-[#0ABFBF]">On the clock</Key>
                {pastShift && <Key swatch="bg-amber-500">Overtime</Key>}
                {nights.length > 0 && (
                    <Key swatch="bg-indigo-500/25">Night window</Key>
                )}
            </figcaption>
        </figure>
    );
}

function Key({ swatch, children }: { swatch: string; children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span aria-hidden className={cn('size-2.5 rounded-sm', swatch)} />
            {children}
        </span>
    );
}

/** What the day comes out as, line by line. */
function Ledger({
    result,
    requiresApproval,
    dimmed,
}: {
    result: DayVerdict;
    requiresApproval: boolean;
    dimmed: boolean;
}) {
    const status = result.status as AttendanceStatus;
    const chips = result.flags.filter(
        (flag) => flag !== 'late' && flag !== 'undertime',
    ) as AttendanceFlag[];

    return (
        <div
            className={cn(
                'space-y-3 transition-opacity',
                dimmed && 'opacity-60',
            )}
        >
            <div className="flex flex-wrap items-center gap-2">
                <span
                    className={cn(
                        'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
                        STATUS_STYLES[status] ?? 'border-border',
                    )}
                >
                    {STATUS_LABELS[status] ?? result.status}
                </span>
                {chips.map((flag) => (
                    <span
                        key={flag}
                        className="rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground"
                    >
                        {FLAG_LABELS[flag] ?? flag}
                    </span>
                ))}
            </div>

            <dl className="divide-y divide-border text-sm">
                <Line label="Regular" minutes={result.regular_minutes} strong />
                <Line
                    label="Overtime"
                    minutes={result.overtime_minutes}
                    note={
                        result.overtime_minutes > 0 && requiresApproval
                            ? 'awaiting approval'
                            : undefined
                    }
                    strong
                />
                <Line
                    label="Late"
                    minutes={result.late_minutes}
                    note={
                        result.excused_late_minutes > 0
                            ? `${formatDuration(result.excused_late_minutes)} forgiven`
                            : undefined
                    }
                />
                <Line label="Short" minutes={result.undertime_minutes} />
                <Line
                    label="Break"
                    minutes={result.break_minutes}
                    note={
                        result.flags.includes('break_deducted')
                            ? 'deducted, none punched'
                            : undefined
                    }
                />
                {result.night_minutes > 0 && (
                    <Line
                        label="Of it at night"
                        minutes={result.night_minutes}
                    />
                )}
                {result.rest_day_minutes > 0 && (
                    <Line
                        label="Of it on a rest day"
                        minutes={result.rest_day_minutes}
                    />
                )}
                {result.holiday_minutes > 0 && (
                    <Line
                        label="Of it on a holiday"
                        minutes={result.holiday_minutes}
                    />
                )}
            </dl>
        </div>
    );
}

function Line({
    label,
    minutes,
    note,
    strong = false,
}: {
    label: string;
    minutes: number;
    note?: string;
    strong?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 py-1.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right tabular-nums">
                {note && (
                    <span className="mr-2 text-xs text-muted-foreground">
                        {note}
                    </span>
                )}
                <span
                    className={cn(
                        minutes === 0 && 'text-muted-foreground/60',
                        strong && minutes > 0 && 'font-semibold',
                    )}
                >
                    {minutes === 0 ? '—' : formatDuration(minutes)}
                </span>
            </dd>
        </div>
    );
}

import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    FREQUENCY_LABELS,
    FREQUENCY_UNITS,
    WEEKDAYS,
    isoWeekday,
} from '../constants';
import type { RepeatFrequency, RepeatRule } from '../types';

const NONE = 'none';

/** How many dates a new repeat starts with, before anyone changes it. */
const DEFAULT_COUNT: Record<RepeatFrequency, number> = {
    daily: 10,
    weekly: 8,
    monthly: 6,
};

type Props = {
    value: RepeatRule | null;
    onChange: (rule: RepeatRule | null) => void;
    /** The first start, as the form holds it (`datetime-local`). */
    startsAt: string;
    errors: Partial<Record<string, string>>;
};

/**
 * "Repeats" on a new event (ADR 0070): every n days, weeks (on chosen
 * weekdays) or months, ending on a date or after a number of times. The first
 * start's own weekday is always one of the days. Up to 100 dates and two years.
 */
export function RepeatFields({ value, onChange, startsAt, errors }: Props) {
    const startDay = isoWeekday(startsAt);
    const set = (patch: Partial<RepeatRule>) =>
        value && onChange({ ...value, ...patch });

    const choose = (frequency: string) =>
        onChange(
            frequency === NONE
                ? null
                : {
                      frequency: frequency as RepeatFrequency,
                      interval: value?.interval ?? 1,
                      weekdays: value?.weekdays ?? [],
                      until: null,
                      count:
                          value?.count ??
                          DEFAULT_COUNT[frequency as RepeatFrequency],
                  },
        );

    const toggleDay = (day: number) =>
        value &&
        set({
            weekdays: value.weekdays.includes(day)
                ? value.weekdays.filter((d) => d !== day)
                : [...value.weekdays, day],
        });

    const error =
        errors.repeat ??
        Object.entries(errors).find(([key]) => key.startsWith('repeat.'))?.[1];

    return (
        <div className="flex flex-col gap-3">
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <Label className="mb-1.5 block">Repeats</Label>
                    <Select
                        value={value?.frequency ?? NONE}
                        onValueChange={choose}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>
                                Does not repeat
                            </SelectItem>
                            {(
                                Object.keys(
                                    FREQUENCY_LABELS,
                                ) as RepeatFrequency[]
                            ).map((frequency) => (
                                <SelectItem key={frequency} value={frequency}>
                                    {FREQUENCY_LABELS[frequency]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {value && (
                    <div>
                        <Label
                            className="mb-1.5 block"
                            htmlFor="repeat-interval"
                        >
                            Every
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id="repeat-interval"
                                type="number"
                                min={1}
                                max={4}
                                value={value.interval}
                                onChange={(e) =>
                                    set({
                                        interval: Math.max(
                                            1,
                                            Math.min(
                                                4,
                                                Number(e.target.value) || 1,
                                            ),
                                        ),
                                    })
                                }
                                className="w-16"
                            />
                            <span className="text-sm text-muted-foreground">
                                {
                                    FREQUENCY_UNITS[value.frequency][
                                        value.interval === 1 ? 0 : 1
                                    ]
                                }
                            </span>
                        </div>
                    </div>
                )}
            </div>

            {value?.frequency === 'weekly' && (
                <div>
                    <Label className="mb-1.5 block">On</Label>
                    <div
                        className="flex gap-1.5"
                        role="group"
                        aria-label="Weekdays"
                    >
                        {WEEKDAYS.map((day) => {
                            const locked = day.value === startDay;
                            const on =
                                locked || value.weekdays.includes(day.value);

                            return (
                                <button
                                    key={day.value}
                                    type="button"
                                    aria-pressed={on}
                                    aria-label={
                                        day.long +
                                        (locked ? ' (the first date)' : '')
                                    }
                                    title={
                                        locked
                                            ? `${day.long} — the first date`
                                            : day.long
                                    }
                                    disabled={locked}
                                    onClick={() => toggleDay(day.value)}
                                    className={cn(
                                        'size-8 rounded-full border text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        on
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF] text-[#0f2044]'
                                            : 'border-border text-muted-foreground hover:bg-muted',
                                        locked && 'cursor-default',
                                    )}
                                >
                                    {day.short}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {value && (
                <div>
                    <Label className="mb-1.5 block">Ends</Label>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <Select
                            value={value.until !== null ? 'until' : 'count'}
                            onValueChange={(mode) =>
                                set(
                                    mode === 'until'
                                        ? {
                                              until:
                                                  startsAt.slice(0, 10) || null,
                                              count: null,
                                          }
                                        : {
                                              until: null,
                                              count: DEFAULT_COUNT[
                                                  value.frequency
                                              ],
                                          },
                                )
                            }
                        >
                            <SelectTrigger className="w-32">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="count">After</SelectItem>
                                <SelectItem value="until">On a date</SelectItem>
                            </SelectContent>
                        </Select>
                        {value.until !== null ? (
                            <Input
                                type="date"
                                value={value.until}
                                min={startsAt.slice(0, 10)}
                                onChange={(e) =>
                                    set({ until: e.target.value || null })
                                }
                                className="w-44"
                                aria-label="Last date"
                            />
                        ) : (
                            <>
                                <Input
                                    type="number"
                                    min={2}
                                    max={100}
                                    value={value.count ?? ''}
                                    onChange={(e) =>
                                        set({
                                            count:
                                                Number(e.target.value) || null,
                                        })
                                    }
                                    className="w-20"
                                    aria-label="Number of dates"
                                />
                                <span className="text-muted-foreground">
                                    dates
                                </span>
                            </>
                        )}
                    </div>
                </div>
            )}

            <InputError message={error} />
        </div>
    );
}

import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

/**
 * The pieces the policy editor writes its rules with (ADR 0038). Every setting
 * reads as a sentence about somebody's day, with the numbers as blanks filled
 * in where they fall: "Count it as a half day when someone is more than
 * [2 hours] late." A rule that is off still reads as the same sentence, greyed,
 * so nobody has to decode "off" from a label that describes "on".
 */

/** Clicks on the words of a sentence act on it; clicks on its blanks don't. */
function isField(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        target.closest('input, select, textarea, button, a') !== null
    );
}

/**
 * A rule that can be switched on or off: a checkbox, then the sentence. With
 * `children` under it, they show only while it is on.
 */
export function Rule({
    checked,
    onCheckedChange,
    sentence,
    hint,
    offHint,
    error,
    children,
}: {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    sentence: ReactNode;
    /** Said under the sentence while it is on (or always, without `offHint`). */
    hint?: ReactNode;
    /** Said under the sentence while it is off. */
    offHint?: ReactNode;
    error?: string;
    children?: ReactNode;
}) {
    const id = useId();
    const note = checked ? hint : (offHint ?? hint);

    return (
        <div className="py-3.5 first:pt-0 last:pb-0">
            <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3">
                <Checkbox
                    checked={checked}
                    onCheckedChange={(value) => onCheckedChange(value === true)}
                    aria-labelledby={`${id}-sentence`}
                    className="mt-[0.4rem] size-[1.05rem] data-[state=checked]:border-[#0a8b91] data-[state=checked]:bg-[#0a8b91]"
                />
                <div
                    id={`${id}-sentence`}
                    onFocus={(event) => {
                        // Filling in a blank of a rule that is off turns it on.
                        if (!checked && isField(event.target)) {
                            onCheckedChange(true);
                        }
                    }}
                    onClick={(event) => {
                        if (!isField(event.target)) {
                            onCheckedChange(!checked);
                        }
                    }}
                    className={cn(
                        'cursor-pointer text-[0.9375rem] leading-7 text-pretty select-none sm:leading-8',
                        !checked && 'text-muted-foreground',
                    )}
                >
                    {sentence}
                </div>
                {note && (
                    <p className="col-start-2 text-[0.8125rem] leading-relaxed text-muted-foreground">
                        {note}
                    </p>
                )}
                {error && (
                    <InputError message={error} className="col-start-2 mt-1" />
                )}
                {checked && children && (
                    <div className="col-start-2 mt-3 space-y-3">{children}</div>
                )}
            </div>
        </div>
    );
}

export type Option<T extends string> = {
    value: T;
    /** The sentence for this choice — blanks inside work only when it is chosen. */
    label: ReactNode | ((chosen: boolean) => ReactNode);
    hint?: ReactNode;
};

/**
 * One of a few ways something can go, each written out in full, with a lead-in
 * sentence above them.
 */
export function Options<T extends string>({
    question,
    value,
    onChange,
    options,
    error,
}: {
    question: ReactNode;
    value: T;
    onChange: (value: T) => void;
    options: Option<T>[];
    error?: string;
}) {
    const name = useId();

    return (
        <fieldset className="py-3.5 first:pt-0 last:pb-0">
            <legend className="mb-2 text-[0.9375rem] font-medium">
                {question}
            </legend>
            <div className="space-y-1">
                {options.map((option) => {
                    const chosen = option.value === value;
                    const id = `${name}-${option.value}`;

                    return (
                        <div
                            key={option.value}
                            onFocus={(event) => {
                                if (
                                    !chosen &&
                                    isField(event.target) &&
                                    (event.target as HTMLInputElement).type !==
                                        'radio'
                                ) {
                                    onChange(option.value);
                                }
                            }}
                            onClick={(event) => {
                                if (!chosen && !isField(event.target)) {
                                    onChange(option.value);
                                }
                            }}
                            className={cn(
                                'grid cursor-pointer grid-cols-[auto_minmax(0,1fr)] gap-x-3 rounded-lg px-3 py-1.5 transition-colors',
                                chosen
                                    ? 'bg-[#0ABFBF]/[0.07] ring-1 ring-[#0ABFBF]/35'
                                    : 'hover:bg-muted/60',
                            )}
                        >
                            <input
                                id={id}
                                type="radio"
                                name={name}
                                checked={chosen}
                                onChange={() => onChange(option.value)}
                                className="mt-[0.55rem] size-4 accent-[#0a8b91]"
                            />
                            <label
                                htmlFor={id}
                                onClick={(event) => {
                                    // The words choose it; a blank inside the
                                    // label keeps its own focus.
                                    if (isField(event.target)) {
                                        event.preventDefault();
                                    }
                                }}
                                className={cn(
                                    'cursor-pointer text-[0.9375rem] leading-7 text-pretty sm:leading-8',
                                    !chosen && 'text-muted-foreground',
                                )}
                            >
                                {typeof option.label === 'function'
                                    ? option.label(chosen)
                                    : option.label}
                            </label>
                            {option.hint && chosen && (
                                <p className="col-start-2 pb-1 text-[0.8125rem] leading-relaxed text-muted-foreground">
                                    {option.hint}
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
            {error && <InputError message={error} className="mt-1.5" />}
        </fieldset>
    );
}

/** A sentence with no switch of its own: the blanks are always live. */
export function Statement({
    sentence,
    hint,
    error,
}: {
    sentence: ReactNode;
    hint?: ReactNode;
    error?: string;
}) {
    return (
        <div className="py-3.5 pl-[1.8rem] first:pt-0 last:pb-0">
            <p className="text-[0.9375rem] leading-7 text-pretty sm:leading-8">
                {sentence}
            </p>
            {hint && (
                <p className="text-[0.8125rem] leading-relaxed text-muted-foreground">
                    {hint}
                </p>
            )}
            {error && <InputError message={error} className="mt-1" />}
        </div>
    );
}

const BLANK =
    'inline-flex h-7 items-baseline gap-1 rounded-md px-1.5 align-baseline ring-1 transition-colors focus-within:ring-2';
const BLANK_ON =
    'bg-[#0ABFBF]/[0.08] ring-[#0ABFBF]/40 focus-within:ring-[#0ABFBF]';
const BLANK_OFF = 'bg-muted/70 ring-border text-muted-foreground';

type DurationUnit = 'min' | 'h';

function unitFor(
    minutes: number | null,
    allowed: DurationUnit[],
): DurationUnit {
    if (allowed.length === 1) {
        return allowed[0];
    }

    return minutes !== null && minutes >= 60 && minutes % 30 === 0
        ? 'h'
        : 'min';
}

function shown(minutes: number, unit: DurationUnit): string {
    return unit === 'h'
        ? String(Math.round((minutes / 60) * 100) / 100)
        : String(minutes);
}

/**
 * A length of time filled into a sentence — in minutes or hours, whichever
 * reads naturally ("2 hours", not "120 min"), with the unit switchable. The
 * value is always minutes.
 */
export function DurationBlank({
    value,
    onChange,
    label,
    units = ['min', 'h'],
    min = 0,
    max,
    muted = false,
}: {
    value: number;
    onChange: (minutes: number) => void;
    /** What the blank is, for a screen reader: "Grace each day". */
    label: string;
    units?: DurationUnit[];
    min?: number;
    max?: number;
    /** Shown greyed: the rule or choice it sits in is off. Editing it turns that on. */
    muted?: boolean;
}) {
    const [unit, setUnit] = useState<DurationUnit>(() => unitFor(value, units));
    const [text, setText] = useState(() => shown(value, unit));
    const last = useRef(value);

    // A value changed from outside (a preset applied, a reset) is shown afresh.
    useEffect(() => {
        if (value !== last.current) {
            last.current = value;
            const next = unitFor(value, units);
            setUnit(next);
            setText(shown(value, next));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `units` is a literal per call site
    }, [value]);

    const commit = (raw: string, as: DurationUnit) => {
        setText(raw);
        const number = Number(raw);

        if (raw.trim() === '' || Number.isNaN(number)) {
            return;
        }

        let minutes = Math.max(
            0,
            Math.round(as === 'h' ? number * 60 : number),
        );

        if (max !== undefined) {
            minutes = Math.min(minutes, max);
        }

        last.current = minutes;
        onChange(minutes);
    };

    return (
        <span className={cn(BLANK, muted ? BLANK_OFF : BLANK_ON)}>
            <input
                type="number"
                inputMode="decimal"
                min={unit === 'h' ? min / 60 : min}
                max={
                    max === undefined
                        ? undefined
                        : unit === 'h'
                          ? max / 60
                          : max
                }
                step={unit === 'h' ? 0.5 : 5}
                value={text}
                aria-label={label}
                onChange={(event) => commit(event.target.value, unit)}
                onBlur={() => setText(shown(value, unit))}
                className="w-[3.25rem] [appearance:textfield] bg-transparent text-center font-semibold tabular-nums outline-none [&::-webkit-inner-spin-button]:appearance-none"
            />
            {units.length > 1 ? (
                <select
                    value={unit}
                    aria-label={`${label}, in`}
                    onChange={(event) => {
                        const next = event.target.value as DurationUnit;
                        setUnit(next);
                        setText(shown(value, next));
                    }}
                    className="cursor-pointer bg-transparent text-[0.8125rem] outline-none"
                >
                    <option value="min">
                        {value === 1 ? 'minute' : 'minutes'}
                    </option>
                    <option value="h">{value === 60 ? 'hour' : 'hours'}</option>
                </select>
            ) : (
                <span className="text-[0.8125rem]">
                    {units[0] === 'h' ? 'hours' : 'minutes'}
                </span>
            )}
        </span>
    );
}

/** A plain number filled into a sentence. */
export function NumberBlank({
    value,
    onChange,
    label,
    suffix,
    min = 0,
    max,
    step = 1,
    muted = false,
}: {
    value: number;
    onChange: (value: number) => void;
    label: string;
    suffix?: string;
    min?: number;
    max?: number;
    step?: number;
    /** Shown greyed: the rule or choice it sits in is off. Editing it turns that on. */
    muted?: boolean;
}) {
    return (
        <span className={cn(BLANK, muted ? BLANK_OFF : BLANK_ON)}>
            <input
                type="number"
                inputMode="numeric"
                min={min}
                max={max}
                step={step}
                value={value}
                aria-label={label}
                onChange={(event) =>
                    onChange(
                        Math.max(0, Math.round(Number(event.target.value))),
                    )
                }
                className="w-[3.25rem] [appearance:textfield] bg-transparent text-center font-semibold tabular-nums outline-none [&::-webkit-inner-spin-button]:appearance-none"
            />
            {suffix && <span className="text-[0.8125rem]">{suffix}</span>}
        </span>
    );
}

/** A clock time filled into a sentence. */
export function TimeBlank({
    value,
    onChange,
    label,
    muted = false,
}: {
    value: string;
    onChange: (value: string) => void;
    label: string;
    /** Shown greyed: the rule or choice it sits in is off. Editing it turns that on. */
    muted?: boolean;
}) {
    return (
        <span className={cn(BLANK, muted ? BLANK_OFF : BLANK_ON)}>
            <input
                type="time"
                value={value}
                aria-label={label}
                onChange={(event) => onChange(event.target.value)}
                className="bg-transparent font-semibold tabular-nums outline-none"
            />
        </span>
    );
}

/** A short pick-one filled into a sentence ("to the nearest [15] minutes"). */
export function SelectBlank<T extends string>({
    value,
    onChange,
    label,
    options,
    muted = false,
}: {
    value: T;
    onChange: (value: T) => void;
    label: string;
    options: { value: T; label: string }[];
    /** Shown greyed: the rule or choice it sits in is off. Editing it turns that on. */
    muted?: boolean;
}) {
    return (
        <span className={cn(BLANK, muted ? BLANK_OFF : BLANK_ON)}>
            <select
                value={value}
                aria-label={label}
                onChange={(event) => onChange(event.target.value as T)}
                className="cursor-pointer bg-transparent font-semibold outline-none"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </span>
    );
}

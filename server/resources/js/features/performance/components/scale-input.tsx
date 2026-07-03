import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { ScaleLevel, ScaleType } from '../types';

type Props = {
    scaleType: ScaleType;
    scaleMin: number;
    scaleMax: number;
    scaleLevels: ScaleLevel[] | null;
    value: number | null;
    onChange: (value: number) => void;
    disabled?: boolean;
};

/** Shared segmented-button styling for the points / descriptive pickers. */
function segmentClass(active: boolean): string {
    return cn(
        'flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm font-semibold tabular-nums transition-colors',
        'disabled:cursor-not-allowed disabled:opacity-60',
        active
            ? 'border-[#0ABFBF] bg-[#0ABFBF] text-white shadow-sm'
            : 'border-border bg-transparent text-muted-foreground hover:border-[#0ABFBF]/40 hover:text-foreground',
    );
}

/**
 * The scale-aware rating control for one scorecard line. It renders the input
 * that fits the criterion's scale: segmented buttons for a compact points scale
 * or descriptive levels, and a bounded number field for a wide points range or a
 * percentage.
 */
export function ScaleInput({
    scaleType,
    scaleMin,
    scaleMax,
    scaleLevels,
    value,
    onChange,
    disabled = false,
}: Props) {
    // Descriptive levels — one labelled button each.
    if (scaleType === 'scale' && scaleLevels && scaleLevels.length > 0) {
        return (
            <div
                className="flex flex-wrap items-center justify-end gap-1.5"
                role="radiogroup"
            >
                {scaleLevels.map((level) => {
                    const active = value === level.value;

                    return (
                        <button
                            key={`${level.label}-${level.value}`}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            disabled={disabled}
                            onClick={() => onChange(level.value)}
                            className={segmentClass(active)}
                        >
                            {level.label}
                        </button>
                    );
                })}
            </div>
        );
    }

    // Compact points scale (≤ 10) — a segmented row of numbers.
    if (scaleType === 'points' && scaleMax - scaleMin <= 9) {
        const options = Array.from(
            { length: Math.round(scaleMax - scaleMin) + 1 },
            (_, i) => Math.round(scaleMin) + i,
        );

        return (
            <div className="inline-flex items-center gap-1" role="radiogroup">
                {options.map((option) => {
                    const active = value === option;

                    return (
                        <button
                            key={option}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            aria-label={`${option}`}
                            disabled={disabled}
                            onClick={() => onChange(option)}
                            className={segmentClass(active)}
                        >
                            {option}
                        </button>
                    );
                })}
            </div>
        );
    }

    // Percentage or a wide points range — a bounded number field.
    const isPercent = scaleType === 'percentage';

    return (
        <div className="flex items-center gap-1.5">
            <Input
                type="number"
                inputMode="decimal"
                min={isPercent ? 0 : scaleMin}
                max={isPercent ? 100 : scaleMax}
                step="1"
                value={value ?? ''}
                disabled={disabled}
                onChange={(event) => {
                    const raw = event.target.value;

                    if (raw !== '') {
                        onChange(Number(raw));
                    }
                }}
                placeholder={isPercent ? '0–100' : `${scaleMin}–${scaleMax}`}
                className="w-24 text-right tabular-nums"
                aria-label="Score"
            />
            {isPercent && (
                <span className="text-sm text-muted-foreground">%</span>
            )}
        </div>
    );
}

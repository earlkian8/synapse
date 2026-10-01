import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { scaleOptions, trimNumber } from '../constants';
import type { PerformanceScore } from '../types';

type Props = {
    line: PerformanceScore;
    onChange: (value: number) => void;
    disabled?: boolean;
};

/**
 * The rating control for one scorecard line, shaped by the scale that line was
 * actually measured on.
 *
 * A descriptive scale gets its levels as words, not numbers — the evaluator
 * picks "Proficient", and the level's behavioural anchor is shown underneath so
 * they are choosing against a definition rather than a vibe. A short numeric
 * scale gets a segmented row. Goal attainment gets a slider and an exact field,
 * because 0–100 is a quantity and not a set of choices.
 */
export function RatingControl({ line, onChange, disabled = false }: Props) {
    const options = scaleOptions(line);

    if (options !== null) {
        const selected = options.find((option) => option.value === line.score);

        return (
            <div className="flex flex-col gap-1.5">
                <div
                    className="flex flex-wrap gap-1.5"
                    role="radiogroup"
                    aria-label={`Rating for ${line.label}`}
                >
                    {options.map((option) => {
                        const active = line.score === option.value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={active}
                                disabled={disabled}
                                title={option.description ?? undefined}
                                onClick={() => onChange(option.value)}
                                className={cn(
                                    'min-h-9 rounded-lg border px-2.5 py-1.5 text-sm font-medium transition-colors',
                                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:outline-none',
                                    'disabled:cursor-not-allowed disabled:opacity-60',
                                    active
                                        ? 'border-[#0ABFBF] bg-[#0ABFBF] text-white shadow-sm'
                                        : 'border-border bg-transparent text-muted-foreground hover:border-[#0ABFBF]/50 hover:text-foreground',
                                )}
                            >
                                {option.label}
                            </button>
                        );
                    })}
                </div>

                {/* The anchor for what was just chosen — the definition the
                    evaluator is rating against, not decoration. */}
                {selected?.description && (
                    <p className="text-xs leading-snug text-muted-foreground">
                        {selected.description}
                    </p>
                )}
            </div>
        );
    }

    const isPercent = line.scale_type === 'percentage';
    const min = line.scale_min;
    const max = line.scale_max;

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
                <Input
                    type="number"
                    inputMode="decimal"
                    min={min}
                    max={max}
                    step={line.scale_step > 0 ? line.scale_step : 1}
                    value={line.score ?? ''}
                    disabled={disabled}
                    onChange={(event) => {
                        const raw = event.target.value;

                        if (raw !== '') {
                            onChange(Number(raw));
                        }
                    }}
                    placeholder={`${trimNumber(min)}–${trimNumber(max)}`}
                    className="h-9 w-24 text-right tabular-nums"
                    aria-label={`Rating for ${line.label}`}
                />
                {isPercent && (
                    <span className="text-sm text-muted-foreground">%</span>
                )}
            </div>

            <input
                type="range"
                min={min}
                max={max}
                step={line.scale_step > 0 ? line.scale_step : 1}
                value={line.score ?? min}
                disabled={disabled}
                onChange={(event) => onChange(Number(event.target.value))}
                aria-label={`${line.label} slider`}
                className="h-1.5 w-full cursor-pointer appearance-none rounded-full bg-muted accent-[#0ABFBF] disabled:cursor-not-allowed disabled:opacity-60"
            />
        </div>
    );
}

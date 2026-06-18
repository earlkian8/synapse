import { cn } from '@/lib/utils';
import { RATING_LABELS, RATING_VALUES } from '../constants';

type Props = {
    value: number | null;
    onChange: (value: number) => void;
    disabled?: boolean;
};

/**
 * A compact 1–5 rating selector: five segmented buttons, the chosen one filled
 * in the brand accent. Each button is labelled for screen readers with what the
 * rating means.
 */
export function RatingInput({ value, onChange, disabled = false }: Props) {
    return (
        <div className="inline-flex items-center gap-1" role="radiogroup">
            {RATING_VALUES.map((rating) => {
                const active = value === rating;

                return (
                    <button
                        key={rating}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        aria-label={`${rating} — ${RATING_LABELS[rating]}`}
                        disabled={disabled}
                        onClick={() => onChange(rating)}
                        className={cn(
                            'flex size-8 items-center justify-center rounded-md border text-sm font-semibold tabular-nums transition-colors',
                            'disabled:cursor-not-allowed disabled:opacity-60',
                            active
                                ? 'border-[#0ABFBF] bg-[#0ABFBF] text-white shadow-sm'
                                : 'border-border bg-transparent text-muted-foreground hover:border-[#0ABFBF]/40 hover:text-foreground',
                        )}
                    >
                        {rating}
                    </button>
                );
            })}
        </div>
    );
}

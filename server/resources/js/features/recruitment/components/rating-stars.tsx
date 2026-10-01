import { Star } from 'lucide-react';
import { cn } from '@/lib/utils';

type Props = {
    value: number | null;
    onChange?: (value: number) => void;
    size?: 'sm' | 'md';
};

/** Five-star rating — read-only, or interactive when `onChange` is given. */
export function RatingStars({ value, onChange, size = 'sm' }: Props) {
    const dimension = size === 'sm' ? 'size-3.5' : 'size-5';

    return (
        <div className="flex items-center gap-0.5">
            {[1, 2, 3, 4, 5].map((star) => {
                const filled = value !== null && star <= value;
                const star_ = (
                    <Star
                        className={cn(
                            dimension,
                            filled
                                ? 'fill-amber-400 text-amber-400'
                                : 'text-muted-foreground/40',
                        )}
                    />
                );

                return onChange ? (
                    <button
                        key={star}
                        type="button"
                        onClick={() => onChange(star)}
                        className="transition-transform hover:scale-110"
                        aria-label={`Rate ${star}`}
                    >
                        {star_}
                    </button>
                ) : (
                    <span key={star}>{star_}</span>
                );
            })}
        </div>
    );
}

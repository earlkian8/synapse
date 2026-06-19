import { cn } from '@/lib/utils';

type Props = {
    percent: number;
    /** Render the bar in a muted/cancelled tone. */
    muted?: boolean;
    className?: string;
};

/** A slim clearance-progress track used on case cards and the case header. */
export function ProgressBar({ percent, muted = false, className }: Props) {
    const clamped = Math.max(0, Math.min(100, percent));

    return (
        <div
            className={cn(
                'h-1.5 w-full overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className={cn(
                    'h-full rounded-full transition-all',
                    muted
                        ? 'bg-muted-foreground/40'
                        : clamped >= 100
                          ? 'bg-emerald-500'
                          : 'bg-[#0ABFBF]',
                )}
                style={{ width: `${clamped}%` }}
            />
        </div>
    );
}

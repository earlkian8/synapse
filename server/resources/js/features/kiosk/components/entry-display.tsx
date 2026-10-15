import { cn } from '@/lib/utils';

/**
 * What has been typed: the employee number as digits, or a PIN as dots — one per
 * digit it takes, so somebody can see how many are left without the PIN being
 * readable over their shoulder.
 */
export function EntryDisplay({
    value,
    secret = false,
    length,
    label,
    shake = false,
}: {
    value: string;
    secret?: boolean;
    /** A PIN's length, drawn as that many places. */
    length?: number;
    label: string;
    /** Shake the places once — the PIN did not match. */
    shake?: boolean;
}) {
    if (secret && length) {
        return (
            <div
                role="img"
                aria-label={`${label}: ${value.length} of ${length} digits`}
                className={cn(
                    'flex h-16 items-center justify-center gap-3.5',
                    shake &&
                        'motion-safe:animate-[kiosk-shake_380ms_ease-in-out]',
                )}
            >
                {Array.from({ length }, (_, index) => (
                    <span
                        key={index}
                        className={cn(
                            'size-4 rounded-full border-2 transition-colors',
                            index < value.length
                                ? 'border-(--k-ink) bg-(--k-ink)'
                                : 'border-(--k-rule) bg-transparent',
                        )}
                    />
                ))}
            </div>
        );
    }

    return (
        <div
            aria-live="polite"
            aria-label={label}
            className="flex h-16 items-center justify-center rounded-2xl border border-(--k-rule) bg-white px-4"
        >
            <span className="truncate text-[2.1rem] font-semibold tracking-[0.06em] text-(--k-ink) tabular-nums">
                {value}
            </span>
            <span
                aria-hidden
                className="ml-0.5 h-8 w-0.5 bg-(--k-signal) motion-safe:animate-caret-blink"
            />
        </div>
    );
}

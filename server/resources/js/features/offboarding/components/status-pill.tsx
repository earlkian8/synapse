import { cn } from '@/lib/utils';

/** A small status badge in the module's pill style. */
export function StatusPill({
    label,
    className,
}: {
    label: string;
    /** The tone's border, fill and text classes. */
    className: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                className,
            )}
        >
            {label}
        </span>
    );
}

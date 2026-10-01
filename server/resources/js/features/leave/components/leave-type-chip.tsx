import { cn } from '@/lib/utils';

/**
 * A small coloured pill for a leave type. The colour is per-type (a stored hex),
 * so it is applied inline rather than through a Tailwind class.
 */
export function LeaveTypeChip({
    name,
    color,
    className,
}: {
    name: string;
    color: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                className,
            )}
            style={{
                color,
                borderColor: `${color}55`,
                backgroundColor: `${color}14`,
            }}
        >
            <span
                className="size-1.5 rounded-full"
                style={{ backgroundColor: color }}
            />
            {name}
        </span>
    );
}

import { cn } from '@/lib/utils';
import { awardColorStyle } from '../constants';

/** A pill for an award type, tinted by its colour. */
export function AwardTypeBadge({
    name,
    color,
    className,
}: {
    name: string;
    color: string | null;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                className,
            )}
            style={awardColorStyle(color)}
        >
            {name}
        </span>
    );
}

import { cn } from '@/lib/utils';
import { EVENT_META } from '../constants';

export function ActivityEventBadge({ event }: { event: string }) {
    const style = EVENT_META[event] ?? EVENT_META.default;
    const label =
        EVENT_META[event]?.label ??
        event.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium',
                style.bg,
                style.text,
            )}
        >
            <span className={cn('size-1.5 rounded-full', style.dot)} />
            {label}
        </span>
    );
}

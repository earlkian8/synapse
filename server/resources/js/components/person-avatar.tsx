import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

type Props = {
    name: string;
    initials: string;
    photo?: string | null;
    /** Extra classes for the avatar shell (size, shape, ring). */
    className?: string;
    /** Extra classes for the initials fallback (e.g. font size). */
    fallbackClassName?: string;
};

/**
 * A person's avatar: their photo when available, falling back to brand-navy
 * initials. Shared across every module that surfaces an employee so the
 * treatment stays consistent.
 */
export function PersonAvatar({
    name,
    initials,
    photo,
    className,
    fallbackClassName,
}: Props) {
    return (
        <Avatar className={cn('size-9 rounded-lg ring-1 ring-border', className)}>
            {photo && <AvatarImage src={photo} alt={name} />}
            <AvatarFallback
                className={cn(
                    'rounded-lg bg-[#0F2044] text-[11px] font-semibold text-white',
                    fallbackClassName,
                )}
            >
                {initials}
            </AvatarFallback>
        </Avatar>
    );
}

import { Server } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import type { ActivityCauser } from '../types';

type Props = {
    causer: ActivityCauser | null;
    className?: string;
};

/**
 * The actor avatar — a user, or a neutral "System" badge when there is no causer.
 */
export function ActorAvatar({ causer, className }: Props) {
    if (!causer) {
        return (
            <Avatar
                className={cn(
                    'size-9 items-center justify-center rounded-full bg-muted ring-1 ring-border',
                    className,
                )}
            >
                <Server className="size-4 text-muted-foreground" />
            </Avatar>
        );
    }

    return (
        <Avatar
            className={cn('size-9 rounded-full ring-1 ring-border', className)}
        >
            {causer.avatar && (
                <AvatarImage src={causer.avatar} alt={causer.full_name} />
            )}
            <AvatarFallback className="rounded-full bg-[#0F2044] text-[11px] font-semibold text-white">
                {causer.initials}
            </AvatarFallback>
        </Avatar>
    );
}

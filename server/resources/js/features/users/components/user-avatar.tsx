import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import type { ManagedUser } from '../types';

type Props = {
    user: Pick<ManagedUser, 'full_name' | 'initials' | 'profile_photo'>;
    className?: string;
};

export function UserAvatar({ user, className }: Props) {
    return (
        <Avatar className={cn('size-9 rounded-full ring-1 ring-border', className)}>
            {user.profile_photo && (
                <AvatarImage src={user.profile_photo} alt={user.full_name} />
            )}
            <AvatarFallback className="rounded-full bg-[#0F2044] text-[11px] font-semibold text-white">
                {user.initials}
            </AvatarFallback>
        </Avatar>
    );
}

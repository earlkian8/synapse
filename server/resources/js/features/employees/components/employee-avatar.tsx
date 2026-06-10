import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

type Props = {
    name: string;
    initials: string;
    photo: string | null;
    className?: string;
};

export function EmployeeAvatar({ name, initials, photo, className }: Props) {
    return (
        <Avatar
            className={cn('size-9 rounded-lg ring-1 ring-border', className)}
        >
            {photo && <AvatarImage src={photo} alt={name} />}
            <AvatarFallback className="rounded-lg bg-[#0F2044] text-[11px] font-semibold text-white">
                {initials}
            </AvatarFallback>
        </Avatar>
    );
}

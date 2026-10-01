import { PersonAvatar } from '@/components/person-avatar';

type Props = {
    name: string;
    initials: string;
    photo: string | null;
    className?: string;
};

export function EmployeeAvatar({ name, initials, photo, className }: Props) {
    return (
        <PersonAvatar
            name={name}
            initials={initials}
            photo={photo}
            className={className}
        />
    );
}

import { CalendarRange, Inbox } from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { leaveRoutes } from '../routes';

export function LeaveNav({ active }: { active: 'requests' | 'balances' }) {
    return (
        <ModuleNav
            label="Leave sections"
            current={active}
            groups={[
                [
                    {
                        key: 'requests',
                        href: leaveRoutes.index,
                        label: 'Requests',
                        icon: Inbox,
                    },
                    {
                        key: 'balances',
                        href: leaveRoutes.balances,
                        label: 'Balances',
                        icon: CalendarRange,
                    },
                ],
            ]}
        />
    );
}

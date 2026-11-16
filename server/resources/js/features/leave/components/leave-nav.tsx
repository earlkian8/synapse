import { CalendarRange, Inbox, UserRound } from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { leaveRoutes } from '../routes';

/**
 * Leave's sections: one's own leave, then what HR keeps — everyone's requests
 * and balances. Each shows only to whoever may open it.
 */
export function LeaveNav({
    active,
}: {
    active: 'me' | 'requests' | 'balances';
}) {
    return (
        <ModuleNav
            label="Leave sections"
            current={active}
            groups={[
                [
                    {
                        key: 'me',
                        href: leaveRoutes.me,
                        label: 'My leave',
                        icon: UserRound,
                        permission: 'leave.request',
                    },
                ],
                [
                    {
                        key: 'requests',
                        href: leaveRoutes.index,
                        label: 'Requests',
                        icon: Inbox,
                        permission: 'leave.view',
                    },
                    {
                        key: 'balances',
                        href: leaveRoutes.balances,
                        label: 'Balances',
                        icon: CalendarRange,
                        permission: 'leave.view',
                    },
                ],
            ]}
        />
    );
}

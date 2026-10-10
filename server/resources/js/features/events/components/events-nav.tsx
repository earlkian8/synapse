import { CalendarCheck2, CalendarClock, DoorOpen } from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { eventRoutes } from '../routes';

export type EventsSection = 'events' | 'rooms' | 'me';

/**
 * The Events & Meetings module's sections (ADR 0070): every event, the rooms
 * they hold, and the person's own invitations — each shown to whoever may open
 * it.
 */
export function EventsNav({ current }: { current: EventsSection }) {
    return (
        <ModuleNav
            label="Events & Meetings sections"
            current={current}
            groups={[
                [
                    {
                        key: 'events',
                        label: 'All events',
                        href: eventRoutes.index,
                        icon: CalendarClock,
                        permission: 'events.view',
                    },
                    {
                        key: 'rooms',
                        label: 'Rooms',
                        href: eventRoutes.rooms,
                        icon: DoorOpen,
                        permission: 'events.view',
                    },
                ],
                [
                    {
                        key: 'me',
                        label: 'My invitations',
                        href: eventRoutes.me,
                        icon: CalendarCheck2,
                        permission: 'events.respond',
                    },
                ],
            ]}
        />
    );
}

import {
    ClipboardList,
    DoorOpen,
    MessageSquareText,
    UserRoundMinus,
} from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { offboardingRoutes } from '../routes';
import type { OffboardingNavCounts } from '../types';

export type OffboardingSection = 'me' | 'exits' | 'resignations' | 'interviews';

/**
 * The Offboarding module's sections (ADR 0076), in two groups: what the person
 * leaving has — their resignation, its clearance, their exit interview and
 * certificate — then what HR runs: every exit, the resignations waiting to be
 * decided, and what exit interviews say. Each is shown to whoever may open it.
 */
export function OffboardingNav({
    current,
    counts,
}: {
    current: OffboardingSection;
    counts?: OffboardingNavCounts | null;
}) {
    return (
        <ModuleNav
            label="Offboarding sections"
            current={current}
            groups={[
                [
                    {
                        key: 'me',
                        label: 'My resignation',
                        href: offboardingRoutes.me,
                        icon: DoorOpen,
                        permission: 'offboarding.resign',
                    },
                ],
                [
                    {
                        key: 'exits',
                        label: 'Exits',
                        href: offboardingRoutes.index,
                        icon: UserRoundMinus,
                        permission: 'offboarding.view',
                    },
                    {
                        key: 'resignations',
                        label: 'Resignations',
                        href: offboardingRoutes.resignations,
                        icon: ClipboardList,
                        permission: 'offboarding.view',
                        count: counts?.resignations,
                    },
                    {
                        key: 'interviews',
                        label: 'Exit interviews',
                        href: offboardingRoutes.interviews,
                        icon: MessageSquareText,
                        permission: 'offboarding.view',
                    },
                ],
            ]}
        />
    );
}

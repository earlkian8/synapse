import {
    BadgeCheck,
    BookOpenCheck,
    CalendarDays,
    GraduationCap,
    LibraryBig,
    Wallet,
} from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { trainingRoutes } from '../routes';
import type { TrainingNavCounts } from '../types';

export type TrainingSection =
    'me' | 'catalog' | 'programs' | 'schedule' | 'certificates' | 'budget';

/**
 * The Training & Development module's sections (ADRs 0074, 0075), in two
 * groups: what everyone taking part has — their own training and the programs
 * they may join — then what HR runs: every program (with the requests to join
 * that wait), the session calendar, the certificate register and the budget.
 * Each is shown to whoever may open it.
 */
export function TrainingNav({
    current,
    counts,
}: {
    current: TrainingSection;
    counts?: TrainingNavCounts | null;
}) {
    return (
        <ModuleNav
            label="Training sections"
            current={current}
            groups={[
                [
                    {
                        key: 'me',
                        label: 'My training',
                        href: trainingRoutes.me,
                        icon: BookOpenCheck,
                        permission: 'training.participate',
                    },
                    {
                        key: 'catalog',
                        label: 'Browse',
                        href: trainingRoutes.catalog,
                        icon: LibraryBig,
                        permission: 'training.participate',
                    },
                ],
                [
                    {
                        key: 'programs',
                        label: 'Programs',
                        href: trainingRoutes.index,
                        icon: GraduationCap,
                        permission: 'training.view',
                        count: counts?.requests,
                    },
                    {
                        key: 'schedule',
                        label: 'Schedule',
                        href: trainingRoutes.schedule(),
                        icon: CalendarDays,
                        permission: 'training.view',
                    },
                    {
                        key: 'certificates',
                        label: 'Certificates',
                        href: trainingRoutes.certificates,
                        icon: BadgeCheck,
                        permission: 'training.view',
                    },
                    {
                        key: 'budget',
                        label: 'Budget',
                        href: trainingRoutes.budget(),
                        icon: Wallet,
                        permission: 'training.view',
                    },
                ],
            ]}
        />
    );
}

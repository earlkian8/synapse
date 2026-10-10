import {
    ClipboardCheck,
    Flag,
    Gauge,
    MessageSquareText,
    Scale,
    Target,
} from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { performanceRoutes } from '../routes';
import type { PerformanceNavCounts } from '../types';

export type PerformanceSection =
    'me' | 'my-goals' | 'reviews' | 'appraisals' | 'goals' | 'calibration';

/**
 * The Performance Management module's sections (ADRs 0072, 0073), in two
 * groups: what everyone taking part has — their appraisals, their goals and the
 * reviews asked of them (with how many wait) — then what HR runs: every
 * appraisal of the cycle, every goal, and the calibration sessions. Each is
 * shown to whoever may open it.
 */
export function PerformanceNav({
    current,
    counts,
}: {
    current: PerformanceSection;
    counts?: PerformanceNavCounts | null;
}) {
    return (
        <ModuleNav
            label="Performance sections"
            current={current}
            groups={[
                [
                    {
                        key: 'me',
                        label: 'My appraisals',
                        href: performanceRoutes.me,
                        icon: ClipboardCheck,
                        permission: 'performance.participate',
                    },
                    {
                        key: 'my-goals',
                        label: 'My goals',
                        href: performanceRoutes.myGoals(),
                        icon: Flag,
                        permission: 'performance.participate',
                    },
                    {
                        key: 'reviews',
                        label: 'Reviews',
                        href: performanceRoutes.reviews,
                        icon: MessageSquareText,
                        permission: 'performance.participate',
                        count: counts?.reviews,
                    },
                ],
                [
                    {
                        key: 'appraisals',
                        label: 'Appraisals',
                        href: performanceRoutes.index,
                        icon: Gauge,
                        permission: 'performance.view',
                    },
                    {
                        key: 'goals',
                        label: 'Goals',
                        href: performanceRoutes.goals(),
                        icon: Target,
                        permission: 'performance.view',
                    },
                    {
                        key: 'calibration',
                        label: 'Calibration',
                        href: performanceRoutes.calibration(),
                        icon: Scale,
                        permission: 'performance.view',
                    },
                ],
            ]}
        />
    );
}

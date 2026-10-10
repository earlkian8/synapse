import {
    Award,
    Gift,
    HandHeart,
    Inbox,
    PackageCheck,
    Send,
} from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { recognitionRoutes } from '@/features/recognition/routes';
import { awardsRoutes } from '../routes';

export type AwardsSection =
    'wall' | 'points' | 'my-nominations' | 'awards' | 'nominations' | 'rewards';

/**
 * The Awards & Recognition module's sections (ADR 0071), in two groups: what
 * everybody taking part has — the wall, their points and their nominations —
 * then what HR runs: the awards register, the nominations to review (with the
 * AI shortlist beside them) and the rewards desk. Each is shown to whoever may
 * open it.
 */
export function AwardsNav({
    current,
    pending,
}: {
    current: AwardsSection;
    /** Nominations waiting for review, shown on its section. */
    pending?: number | null;
}) {
    return (
        <ModuleNav
            label="Awards & Recognition sections"
            current={current}
            groups={[
                [
                    {
                        key: 'wall',
                        label: 'Wall',
                        href: recognitionRoutes.wall,
                        icon: HandHeart,
                        permission: 'awards.participate',
                    },
                    {
                        key: 'points',
                        label: 'My points',
                        href: recognitionRoutes.rewards,
                        icon: Gift,
                        permission: 'awards.participate',
                    },
                    {
                        key: 'my-nominations',
                        label: 'My nominations',
                        href: recognitionRoutes.nominations,
                        icon: Send,
                        permission: 'awards.participate',
                    },
                ],
                [
                    {
                        key: 'awards',
                        label: 'Awards',
                        href: awardsRoutes.index,
                        icon: Award,
                        permission: 'awards.view',
                    },
                    {
                        key: 'nominations',
                        label: 'Nominations',
                        href: awardsRoutes.nominations,
                        icon: Inbox,
                        permission: 'awards.manage',
                        count: pending,
                    },
                    {
                        key: 'rewards',
                        label: 'Rewards',
                        href: awardsRoutes.rewards,
                        icon: PackageCheck,
                        permission: 'awards.manage',
                    },
                ],
            ]}
        />
    );
}

import { BarChart3, SmilePlus } from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { checkInRoutes } from '../routes';

export type CheckInsSection = 'me' | 'results';

/**
 * The Check-ins sections (ADR 0102): one's own check-in, then — for HR and
 * department heads — the team results. Each shown to whoever may open it.
 */
export function CheckInsNav({ current }: { current: CheckInsSection }) {
    return (
        <ModuleNav
            label="Check-ins sections"
            current={current}
            groups={[
                [
                    {
                        key: 'me',
                        label: 'My check-in',
                        href: checkInRoutes.me,
                        icon: SmilePlus,
                        permission: 'check-ins.respond',
                    },
                ],
                [
                    {
                        key: 'results',
                        label: 'Team results',
                        href: checkInRoutes.results(),
                        icon: BarChart3,
                        permission: 'check-ins.view',
                    },
                ],
            ]}
        />
    );
}

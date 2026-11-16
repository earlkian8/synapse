import { BookOpenCheck, FileWarning, Inbox, Scale } from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { disciplineRoutes } from '../routes';

export type DisciplineSection = 'me' | 'cases' | 'reported' | 'code';

/**
 * Discipline's sections (ADR 0105): the notices served on oneself, then what
 * HR and supervisors keep — the cases, the reports one filed, and the code of
 * conduct cases are charged under. Each shows only to whoever may open it.
 */
export function DisciplineNav({ current }: { current: DisciplineSection }) {
    return (
        <ModuleNav
            label="Discipline sections"
            current={current}
            groups={[
                [
                    {
                        key: 'me',
                        href: disciplineRoutes.me,
                        label: 'My notices',
                        icon: Inbox,
                        permission: 'discipline.respond',
                    },
                ],
                [
                    {
                        key: 'cases',
                        href: disciplineRoutes.cases,
                        label: 'Cases',
                        icon: Scale,
                        permission: 'discipline.view',
                    },
                    {
                        key: 'reported',
                        href: disciplineRoutes.reported,
                        label: 'Reported by me',
                        icon: FileWarning,
                        permission: 'discipline.report',
                    },
                    {
                        key: 'code',
                        href: disciplineRoutes.code,
                        label: 'Code of conduct',
                        icon: BookOpenCheck,
                        permission: 'discipline.manage',
                    },
                ],
            ]}
        />
    );
}

import { BriefcaseBusiness, MessagesSquare } from 'lucide-react';
import { ModuleNav } from '@/components/module-nav';
import { recruitmentRoutes } from '../routes';

/**
 * Recruitment's sections (ADR 0091): the interviews you are assigned, then the
 * job postings HR runs. Each shows only to whoever may open it, so somebody
 * who only interviews sees no switcher at all.
 */
export function RecruitmentNav({
    current,
    pending,
}: {
    current: 'interviews' | 'postings';
    /** How many of your interviews still need a verdict. */
    pending?: number;
}) {
    return (
        <ModuleNav
            label="Recruitment sections"
            current={current}
            groups={[
                [
                    {
                        key: 'interviews',
                        href: recruitmentRoutes.myInterviews,
                        label: 'My interviews',
                        icon: MessagesSquare,
                        permission: 'recruitment.interview',
                        count: pending,
                    },
                ],
                [
                    {
                        key: 'postings',
                        href: recruitmentRoutes.index,
                        label: 'Job postings',
                        icon: BriefcaseBusiness,
                        permission: 'recruitment.view',
                    },
                ],
            ]}
        />
    );
}

import {
    Briefcase,
    CalendarClock,
    FileSignature,
    GitBranch,
    UserCheck,
    Users,
} from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { RecruitmentStats } from '../types';

/** Recruitment's six headline counts. */
export function RecruitmentStatsCards({ stats }: { stats: RecruitmentStats }) {
    return (
        <StatTiles
            tiles={[
                {
                    key: 'open',
                    label: 'Open postings',
                    value: stats.open_postings.toLocaleString(),
                    icon: Briefcase,
                    accent: 'teal',
                },
                {
                    key: 'applicants',
                    label: 'Applicants',
                    value: stats.total_applicants.toLocaleString(),
                    icon: Users,
                    accent: 'sky',
                },
                {
                    key: 'pipeline',
                    label: 'In pipeline',
                    value: stats.in_pipeline.toLocaleString(),
                    icon: GitBranch,
                    accent: 'violet',
                },
                {
                    key: 'final',
                    label: 'In final stage',
                    value: stats.final_stage.toLocaleString(),
                    icon: FileSignature,
                    accent: 'amber',
                },
                {
                    key: 'interviews',
                    label: 'Interviews ahead',
                    value: stats.interviews_upcoming.toLocaleString(),
                    icon: CalendarClock,
                    accent: 'indigo',
                },
                {
                    key: 'hired',
                    label: 'Hired this month',
                    value: stats.hired_this_month.toLocaleString(),
                    icon: UserCheck,
                    accent: 'emerald',
                },
            ]}
        />
    );
}

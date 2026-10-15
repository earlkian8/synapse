import { Head, usePage } from '@inertiajs/react';
import { PageBody, PageHeader } from '@/components/data-table';
import { AttendanceNav } from '@/features/attendance/components/attendance-nav';
import { RosterManager } from '@/features/roster/components/roster-manager';
import { rosterRoutes } from '@/features/roster/routes';
import type { RosterPageProps } from '@/features/roster/types';

/**
 * Attendance → Shift roster (ADR 0037, 0090): the plan each day of attendance
 * is judged against, beside the time records of what happened.
 */
export default function AttendanceRoster() {
    const props = usePage<RosterPageProps>().props;

    return (
        <>
            <Head title="Shift Roster" />

            <PageBody>
                <PageHeader
                    title="Shift Roster"
                    description="Who is due to work what, day by day, and why that shift applies. Each day's attendance is judged against it."
                    actions={<AttendanceNav current="roster" />}
                />

                <RosterManager {...props} />
            </PageBody>
        </>
    );
}

AttendanceRoster.layout = {
    breadcrumbs: [
        { title: 'Attendance', href: '/attendance' },
        { title: 'Shift Roster', href: rosterRoutes.index },
    ],
};

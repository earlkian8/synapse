import { Head, usePage } from '@inertiajs/react';
import { PageBody, PageHeader } from '@/components/data-table';
import { AttendanceNav } from '@/features/attendance/components/attendance-nav';
import { KiosksManager } from '@/features/kiosks/components/kiosks-manager';
import { kioskAdminRoutes } from '@/features/kiosks/routes';
import type { KiosksPageProps } from '@/features/kiosks/types';

/**
 * Attendance → Kiosks (ADR 0092): the shared devices at a site where people
 * without a phone clock in, and who may — beside the time records their punches
 * end up in.
 */
export default function AttendanceKiosks() {
    const props = usePage<KiosksPageProps>().props;

    return (
        <>
            <Head title="Kiosks" />

            <PageBody>
                <PageHeader
                    title="Kiosks"
                    description="Shared tablets at your sites, where people without a phone clock in with their employee number and a PIN."
                    actions={<AttendanceNav current="kiosks" />}
                />

                <KiosksManager {...props} />
            </PageBody>
        </>
    );
}

AttendanceKiosks.layout = {
    breadcrumbs: [
        { title: 'Attendance', href: '/attendance' },
        { title: 'Kiosks', href: kioskAdminRoutes.index },
    ],
};

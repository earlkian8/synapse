import { Head, usePage } from '@inertiajs/react';
import { PageBody, PageHeader } from '@/components/data-table';
import { AttendanceNav } from '@/features/attendance/components/attendance-nav';
import { QrStationsManager } from '@/features/qr-stations/components/qr-stations-manager';
import { qrStationRoutes } from '@/features/qr-stations/routes';
import type { QrStationsPageProps } from '@/features/qr-stations/types';

/**
 * Attendance → QR stations (ADR 0103): the screens at a site whose changing code
 * people scan with their own phone to clock in, and every recent scan of them.
 */
export default function AttendanceQrStations() {
    const props = usePage<QrStationsPageProps>().props;

    return (
        <>
            <Head title="QR stations" />

            <PageBody>
                <PageHeader
                    title="QR stations"
                    description="Screens at your sites with a code that changes every few seconds. People scan it with their phone to clock in, and only from the site."
                    actions={<AttendanceNav current="qr" />}
                />

                <QrStationsManager {...props} />
            </PageBody>
        </>
    );
}

AttendanceQrStations.layout = {
    breadcrumbs: [
        { title: 'Attendance', href: '/attendance' },
        { title: 'QR stations', href: qrStationRoutes.index },
    ],
};

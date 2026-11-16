import { Head, usePage } from '@inertiajs/react';
import { QrScanScreen } from '@/features/qr-scan/components/qr-scan-screen';
import type { QrScanPageProps } from '@/features/qr-scan/types';

/**
 * A QR station's code, opened by a phone's own camera (ADR 0103): clock in
 * without the app, with an employee number and PIN. Nobody is signed in here.
 */
export default function QrScan() {
    const props = usePage<QrScanPageProps>().props;

    return (
        <>
            <Head title="Clock in" />
            <QrScanScreen {...props} />
        </>
    );
}

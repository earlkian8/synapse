import { Head, usePage } from '@inertiajs/react';
import { QrDisplayScreen } from '@/features/qr-stations/components/qr-display-screen';
import type { QrDisplayProps } from '@/features/qr-stations/types';

/**
 * A QR station's screen (ADR 0103), with no app around it: the code, the time,
 * how to scan and who just did. It asks for each code as it is due.
 */
export default function QrDisplay() {
    const props = usePage<QrDisplayProps>().props;

    return (
        <>
            <Head title={props.station.name}>
                <meta name="robots" content="noindex" />
            </Head>

            <QrDisplayScreen {...props} />
        </>
    );
}

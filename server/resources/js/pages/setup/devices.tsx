import { Head, usePage } from '@inertiajs/react';
import { DevicesManager } from '@/features/devices/components/devices-manager';
import type { DevicesPageProps } from '@/features/devices/types';

/**
 * Company Setup → Devices (ADR 0040): the kiosks and biometric scanners that
 * send punches. A device is a key, shown once; its punches are recorded as it
 * sent them and flagged when they do not add up, never refused.
 */
export default function SetupDevices() {
    const props = usePage<DevicesPageProps>().props;

    return (
        <>
            <Head title="Devices" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <DevicesManager
                    {...props}
                    heading={
                        <div className="flex max-w-2xl flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Devices
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Kiosks and biometric scanners that send punches.
                                A device’s punches are recorded exactly as it
                                sent them; anything that doesn’t add up is
                                flagged for sign-off rather than lost.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupDevices.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Devices', href: '/setup/devices' },
    ],
};

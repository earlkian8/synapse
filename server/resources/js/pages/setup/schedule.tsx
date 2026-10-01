import { Head, usePage } from '@inertiajs/react';
import { ScheduleManager } from '@/features/schedule-config/components/schedule-manager';
import type { ScheduleSetupPageProps } from '@/features/schedule-config/types';

export default function SetupSchedule() {
    const props = usePage<ScheduleSetupPageProps>().props;

    return (
        <>
            <Head title="Work Schedule & Holidays" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Work Schedule &amp; Holidays
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        The shift patterns employees are assigned to (read by
                        Attendance) and the holiday calendar (a holiday is not
                        charged as a leave day).
                    </p>
                </div>

                <ScheduleManager {...props} />
            </div>
        </>
    );
}

SetupSchedule.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Work Schedule & Holidays', href: '/setup/schedule' },
    ],
};

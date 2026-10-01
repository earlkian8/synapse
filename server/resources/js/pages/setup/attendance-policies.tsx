import { Head, usePage } from '@inertiajs/react';
import { PoliciesManager } from '@/features/attendance-policy-config/components/policies-manager';
import type { AttendancePoliciesPageProps } from '@/features/attendance-policy-config/types';

/**
 * Company Setup → Attendance Policies (ADR 0038): how each day is judged —
 * grace, rounding, lateness thresholds, overtime, breaks and night work — chosen
 * from a preset and adjusted, never written as a formula.
 */
export default function SetupAttendancePolicies() {
    const props = usePage<AttendancePoliciesPageProps>().props;

    return (
        <>
            <Head title="Attendance Policies" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PoliciesManager
                    {...props}
                    heading={
                        <div className="flex max-w-2xl flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Attendance Policies
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                How a day is judged — when someone is late or
                                short, how times are rounded, what counts as
                                overtime and night work. A day keeps the policy
                                it was judged by.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupAttendancePolicies.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Attendance Policies', href: '/setup/attendance-policies' },
    ],
};

import { Head, usePage } from '@inertiajs/react';
import { RosterManager } from '@/features/roster/components/roster-manager';
import type { RosterPageProps } from '@/features/roster/types';

export default function SetupRoster() {
    const props = usePage<RosterPageProps>().props;

    return (
        <>
            <Head title="Shift Roster" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <RosterManager
                    {...props}
                    heading={
                        <div className="flex flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Shift Roster
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Who is due to work what, day by day, and why
                                that shift applies. Attendance judges each day
                                against it.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupRoster.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Shift Roster', href: '/setup/roster' },
    ],
};

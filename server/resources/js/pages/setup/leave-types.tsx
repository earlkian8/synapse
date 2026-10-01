import { Head, usePage } from '@inertiajs/react';
import { LeaveTypesManager } from '@/features/leave-types/components/leave-types-manager';
import type { LeaveTypesPageProps } from '@/features/leave-types/types';

export default function SetupLeaveTypes() {
    const props = usePage<LeaveTypesPageProps>().props;

    return (
        <>
            <Head title="Leave Types" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <LeaveTypesManager
                    {...props}
                    heading={
                        <div className="flex flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Leave Types
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                The kinds of leave the organisation grants, with
                                their entitlement and policy.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupLeaveTypes.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Leave Types', href: '/setup/leave-types' },
    ],
};

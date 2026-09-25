import { Head, usePage } from '@inertiajs/react';
import { DepartmentsManager } from '@/features/departments/components/departments-manager';
import type { DepartmentsPageProps } from '@/features/departments/types';

export default function SetupDepartments() {
    const props = usePage<DepartmentsPageProps>().props;

    return (
        <>
            <Head title="Departments" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Departments
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Define the org structure — departments, their hierarchy,
                        heads and the positions under each.
                    </p>
                </div>

                <DepartmentsManager {...props} />
            </div>
        </>
    );
}

SetupDepartments.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Departments', href: '/setup/departments' },
    ],
};

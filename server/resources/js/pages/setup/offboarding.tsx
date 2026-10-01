import { Head, usePage } from '@inertiajs/react';
import { OffboardingProgramsManager } from '@/features/offboarding/components/programs-manager';
import type { ProgramsPageProps } from '@/features/offboarding/types';

export default function SetupOffboarding() {
    const props = usePage<ProgramsPageProps>().props;

    return (
        <>
            <Head title="Offboarding Programs" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <OffboardingProgramsManager
                    {...props}
                    heading={
                        <div className="flex flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Offboarding Programs
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Reusable clearance templates that seed each
                                exit's checklist. Applied automatically when
                                offboarding starts.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupOffboarding.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Offboarding Programs', href: '/setup/offboarding' },
    ],
};

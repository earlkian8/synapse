import { Head, usePage } from '@inertiajs/react';
import { OnboardingProgramsManager } from '@/features/onboarding/components/programs-manager';
import type { ProgramsPageProps } from '@/features/onboarding/types';

export default function SetupOnboarding() {
    const props = usePage<ProgramsPageProps>().props;

    return (
        <>
            <Head title="Onboarding Programs" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <OnboardingProgramsManager
                    {...props}
                    heading={
                        <div className="flex flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Onboarding Programs
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Reusable checklists that seed each new hire's
                                onboarding. Applied automatically when someone
                                is hired.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupOnboarding.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Onboarding Programs', href: '/setup/onboarding' },
    ],
};

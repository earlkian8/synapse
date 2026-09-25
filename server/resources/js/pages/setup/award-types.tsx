import { Head, usePage } from '@inertiajs/react';
import { AwardTypesManager } from '@/features/award-types-config/components/award-types-manager';
import type { AwardTypeSetupPageProps } from '@/features/award-types-config/types';

export default function SetupAwardTypes() {
    const props = usePage<AwardTypeSetupPageProps>().props;

    return (
        <>
            <Head title="Award Types" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <AwardTypesManager
                    {...props}
                    heading={
                        <div className="flex flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Award Types
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                The recognitions the organisation gives out.
                                Recognise employees from the Awards module.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupAwardTypes.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Award Types', href: '/setup/award-types' },
    ],
};

import { Head, usePage } from '@inertiajs/react';
import { KpiManager } from '@/features/kpi-config/components/kpi-manager';
import type { KpiSetupPageProps } from '@/features/kpi-config/types';

export default function SetupKpi() {
    const props = usePage<KpiSetupPageProps>().props;

    return (
        <>
            <Head title="Performance framework" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Performance framework
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        How this company reviews its people: the frameworks
                        appraisals are conducted against, the scales they
                        measure on, the criteria they draw from, the cycles they
                        run in, and the goals teams start from.
                    </p>
                </div>

                <KpiManager {...props} />
            </div>
        </>
    );
}

SetupKpi.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Performance framework', href: '/setup/kpi' },
    ],
};

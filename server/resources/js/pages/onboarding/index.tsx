import { Head, usePage } from '@inertiajs/react';
import { UserRoundCheck } from 'lucide-react';
import { useState } from 'react';
import { OnboardingCaseCard } from '@/features/onboarding/components/onboarding-case-card';
import { OnboardingStatsCards } from '@/features/onboarding/components/onboarding-stats';
import { OnboardingToolbar } from '@/features/onboarding/components/onboarding-toolbar';
import { StartOnboardingDialog } from '@/features/onboarding/components/start-onboarding-dialog';
import { useOnboardingFilters } from '@/features/onboarding/hooks/use-onboarding-filters';
import type { IndexPageProps } from '@/features/onboarding/types';

export default function OnboardingIndex() {
    const { cases, stats, options, can, filters } =
        usePage<IndexPageProps>().props;
    const { setSearch, setStatus, setDepartment, reset } =
        useOnboardingFilters(filters);

    const [startOpen, setStartOpen] = useState(false);

    return (
        <>
            <Head title="Onboarding" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Onboarding
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Guide every new hire from day one to fully productive
                        with a structured checklist.
                    </p>
                </div>

                <OnboardingStatsCards stats={stats} />

                <div className="flex flex-col gap-4">
                    <OnboardingToolbar
                        filters={filters}
                        departments={options.departments}
                        canManage={can.manage}
                        canManagePrograms={can.managePrograms}
                        onSearch={setSearch}
                        onStatus={setStatus}
                        onDepartment={setDepartment}
                        onReset={reset}
                        onStart={() => setStartOpen(true)}
                    />

                    {cases.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {cases.map((c) => (
                                <OnboardingCaseCard key={c.id} case={c} />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <StartOnboardingDialog
                employees={options.employees}
                programs={options.programs}
                open={startOpen}
                onOpenChange={setStartOpen}
            />
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <UserRoundCheck className="size-5" />
            </span>
            <p className="text-sm font-medium">No onboarding here</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                New hires from Recruitment land here automatically. You can also
                start onboarding for any employee.
            </p>
        </div>
    );
}

OnboardingIndex.layout = {
    breadcrumbs: [{ title: 'Onboarding', href: '/onboarding' }],
};

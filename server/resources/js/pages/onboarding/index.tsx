import { Head, Link, usePage } from '@inertiajs/react';
import { ListChecks, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { OnboardingStatsCards } from '@/features/onboarding/components/onboarding-stats';
import { ProgramsOverviewTable } from '@/features/onboarding/components/programs-overview-table';
import { SearchInput } from '@/features/onboarding/components/search-input';
import { StartOnboardingDialog } from '@/features/onboarding/components/start-onboarding-dialog';
import { useProgramSearch } from '@/features/onboarding/hooks/use-program-search';
import { onboardingRoutes } from '@/features/onboarding/routes';
import type { IndexPageProps } from '@/features/onboarding/types';

/**
 * Onboarding, first level: every program, with how many people it is onboarding
 * and how that is going. A program opens the people it covers; a person opens
 * their checklist.
 */
export default function OnboardingIndex() {
    const { programs, stats, options, can, filters } =
        usePage<IndexPageProps>().props;
    const search = useProgramSearch();

    const [startOpen, setStartOpen] = useState(false);
    const [startProgram, setStartProgram] = useState<number | null>(null);

    const openStart = (programId: number | null) => {
        setStartProgram(programId);
        setStartOpen(true);
    };

    return (
        <>
            <Head title="Onboarding" />

            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-0.5">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Onboarding
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Every program and the new hires going through it. Open a
                        program to see its people.
                    </p>
                </div>

                <OnboardingStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <SearchInput
                            value={filters.search}
                            onSearch={search}
                            placeholder="Search programs…"
                            label="Search programs"
                        />

                        <div className="flex items-center gap-2">
                            {can.managePrograms && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={onboardingRoutes.programs}>
                                        <ListChecks className="size-4" />
                                        Manage programs
                                    </Link>
                                </Button>
                            )}
                            {can.manage && (
                                <Button
                                    size="sm"
                                    onClick={() => openStart(null)}
                                >
                                    <Plus className="size-4" />
                                    Start onboarding
                                </Button>
                            )}
                        </div>
                    </div>

                    <ProgramsOverviewTable
                        programs={programs}
                        can={can}
                        searching={filters.search !== ''}
                        onStart={openStart}
                    />
                </div>
            </div>

            <StartOnboardingDialog
                employees={options.employees}
                programs={options.programs}
                programId={startProgram}
                open={startOpen}
                onOpenChange={setStartOpen}
            />
        </>
    );
}

OnboardingIndex.layout = {
    breadcrumbs: [{ title: 'Onboarding', href: onboardingRoutes.index }],
};

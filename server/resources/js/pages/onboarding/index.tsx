import { Head, Link, usePage } from '@inertiajs/react';
import { ListChecks, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { OnboardingStatsCards } from '@/features/onboarding/components/onboarding-stats';
import { ProgramsOverviewTable } from '@/features/onboarding/components/programs-overview-table';
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

            <PageBody>
                <PageHeader
                    title="Onboarding"
                    description="Every program and the new hires going through it. Open a program to see its people."
                />

                <OnboardingStatsCards stats={stats} />

                <ListToolbar
                    actions={
                        <>
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
                        </>
                    }
                >
                    <SearchInput
                        value={filters.search}
                        onSearch={search}
                        placeholder="Search programs…"
                        label="Search programs"
                    />
                </ListToolbar>

                <ProgramsOverviewTable
                    programs={programs}
                    can={can}
                    searching={filters.search !== ''}
                    onStart={openStart}
                />
            </PageBody>

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

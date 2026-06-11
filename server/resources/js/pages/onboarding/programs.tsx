import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ListChecks, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/onboarding/components/confirm-dialog';
import { ProgramCard } from '@/features/onboarding/components/program-card';
import { ProgramFormSheet } from '@/features/onboarding/components/program-form-sheet';
import { onboardingRoutes } from '@/features/onboarding/routes';
import type {
    OnboardingProgram,
    ProgramsPageProps,
} from '@/features/onboarding/types';

export default function OnboardingPrograms() {
    const { programs, options, can } = usePage<ProgramsPageProps>().props;

    const [formProgram, setFormProgram] = useState<OnboardingProgram | null>(
        null,
    );
    const [formOpen, setFormOpen] = useState(false);
    const [target, setTarget] = useState<OnboardingProgram | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const openCreate = () => {
        setFormProgram(null);
        setFormOpen(true);
    };

    const openEdit = (program: OnboardingProgram) => {
        setFormProgram(program);
        setFormOpen(true);
    };

    const askDelete = (program: OnboardingProgram) => {
        setTarget(program);
        setConfirmOpen(true);
    };

    const remove = () => {
        if (!target) {
            return;
        }

        router.delete(onboardingRoutes.program(target.hashid), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Onboarding programs" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex items-start gap-3">
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-9 shrink-0"
                            asChild
                        >
                            <Link
                                href={onboardingRoutes.index}
                                aria-label="Back to onboarding"
                            >
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">
                                Onboarding programs
                            </h1>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Reusable checklists that seed each new hire's
                                onboarding.
                            </p>
                        </div>
                    </div>

                    {can.managePrograms && (
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" />
                            New program
                        </Button>
                    )}
                </div>

                {programs.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {programs.map((program) => (
                            <ProgramCard
                                key={program.id}
                                program={program}
                                canManage={can.managePrograms}
                                onEdit={openEdit}
                                onDelete={askDelete}
                            />
                        ))}
                    </div>
                )}
            </div>

            <ProgramFormSheet
                program={formProgram}
                departments={options.departments}
                open={formOpen}
                onOpenChange={setFormOpen}
            />

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={`Delete "${target?.name}"?`}
                description="In-flight onboarding keeps its tasks; only the template is removed."
                confirmLabel="Delete program"
                destructive
                processing={processing}
                onConfirm={remove}
            />
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <ListChecks className="size-5" />
            </span>
            <p className="text-sm font-medium">No programs yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Create a program to define the checklist new hires are onboarded
                with.
            </p>
        </div>
    );
}

OnboardingPrograms.layout = {
    breadcrumbs: [
        { title: 'Onboarding', href: '/onboarding' },
        { title: 'Programs', href: '/onboarding/programs' },
    ],
};

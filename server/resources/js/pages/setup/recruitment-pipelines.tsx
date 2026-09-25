import { Head, usePage } from '@inertiajs/react';
import { PipelinesManager } from '@/features/recruitment-pipelines/components/pipelines-manager';
import type { PipelinesPageProps } from '@/features/recruitment-pipelines/types';

export default function SetupRecruitmentPipelines() {
    const props = usePage<PipelinesPageProps>().props;

    return (
        <>
            <Head title="Recruitment Pipelines" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PipelinesManager
                    {...props}
                    heading={
                        <div className="flex flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Recruitment Pipelines
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                The hiring processes your job postings can run
                                on — each a named, ordered set of stages. Assign
                                a different one to any role that needs its own
                                process.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupRecruitmentPipelines.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        {
            title: 'Recruitment Pipelines',
            href: '/setup/recruitment-pipelines',
        },
    ],
};

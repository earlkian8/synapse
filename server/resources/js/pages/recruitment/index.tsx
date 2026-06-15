import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/features/recruitment/components/confirm-dialog';
import { PostingDetailSheet } from '@/features/recruitment/components/posting-detail-sheet';
import { PostingFormSheet } from '@/features/recruitment/components/posting-form-sheet';
import { PostingsGrid } from '@/features/recruitment/components/postings-grid';
import { PostingsPagination } from '@/features/recruitment/components/postings-pagination';
import { PostingsTable } from '@/features/recruitment/components/postings-table';
import { PostingsToolbar } from '@/features/recruitment/components/postings-toolbar';
import { RecruitmentStatsCards } from '@/features/recruitment/components/recruitment-stats';
import { usePostingsFilters } from '@/features/recruitment/hooks/use-postings-filters';
import { usePostingsView } from '@/features/recruitment/hooks/use-postings-view';
import { recruitmentRoutes } from '@/features/recruitment/routes';
import type {
    ManagedPosting,
    PostingsPageProps,
} from '@/features/recruitment/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function RecruitmentIndex() {
    const { postings, stats, options, can, filters } =
        usePage<PostingsPageProps>().props;
    const {
        setSearch,
        setStatus,
        setDepartment,
        setPerPage,
        setPage,
        toggleSort,
        reset,
    } = usePostingsFilters(filters);
    const { view, changeView } = usePostingsView();

    const [formPosting, setFormPosting] = useState<ManagedPosting | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [detailPosting, setDetailPosting] = useState<ManagedPosting | null>(
        null,
    );
    const [detailOpen, setDetailOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const openPipeline = (posting: ManagedPosting) =>
        router.visit(recruitmentRoutes.show(posting.hashid));

    const openDetail = (posting: ManagedPosting) => {
        setDetailPosting(posting);
        setDetailOpen(true);
    };

    const openCreate = () => {
        setFormPosting(null);
        setFormOpen(true);
    };

    const openEdit = (posting: ManagedPosting) => {
        setFormPosting(posting);
        setFormOpen(true);
    };

    const setStatusFor = (posting: ManagedPosting, status: string) =>
        router.patch(
            recruitmentRoutes.status(posting.hashid),
            { status },
            { preserveScroll: true },
        );

    const remove = (posting: ManagedPosting) =>
        askConfirm({
            title: `Delete "${posting.title}"?`,
            description:
                'This permanently removes the posting and every application in its pipeline. This cannot be undone.',
            confirmLabel: 'Delete posting',
            run: () =>
                router.delete(recruitmentRoutes.destroy(posting.hashid), {
                    preserveScroll: true,
                    onStart: () => setProcessing(true),
                    onFinish: () => {
                        setProcessing(false);
                        setConfirmOpen(false);
                    },
                }),
        });

    return (
        <>
            <Head title="Recruitment" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Recruitment
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Post vacancies, track applicants through the hiring
                        pipeline, and hire.
                    </p>
                </div>

                <RecruitmentStatsCards stats={stats} />

                <div className="flex flex-col gap-4">
                    <PostingsToolbar
                        filters={filters}
                        departments={options.departments}
                        canCreate={can.create}
                        canExport={can.export}
                        view={view}
                        onSearch={setSearch}
                        onStatus={setStatus}
                        onDepartment={setDepartment}
                        onView={changeView}
                        onReset={reset}
                        onCreate={openCreate}
                    />

                    {view === 'table' ? (
                        <PostingsTable
                            postings={postings.data}
                            filters={filters}
                            can={can}
                            onToggleSort={toggleSort}
                            onView={openDetail}
                            onOpen={openPipeline}
                            onEdit={openEdit}
                            onStatus={setStatusFor}
                            onDelete={remove}
                        />
                    ) : (
                        <PostingsGrid
                            postings={postings.data}
                            can={can}
                            onView={openDetail}
                            onOpen={openPipeline}
                            onEdit={openEdit}
                            onStatus={setStatusFor}
                            onDelete={remove}
                        />
                    )}

                    <PostingsPagination
                        meta={postings.meta}
                        perPage={filters.per_page}
                        onPage={setPage}
                        onPerPage={setPerPage}
                    />
                </div>
            </div>

            <PostingDetailSheet
                posting={detailPosting}
                open={detailOpen}
                can={can}
                onOpenChange={setDetailOpen}
                onOpenPipeline={openPipeline}
                onEdit={openEdit}
            />

            <PostingFormSheet
                posting={formPosting}
                options={options}
                open={formOpen}
                onOpenChange={setFormOpen}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

RecruitmentIndex.layout = {
    breadcrumbs: [
        {
            title: 'Recruitment',
            href: '/recruitment',
        },
    ],
};

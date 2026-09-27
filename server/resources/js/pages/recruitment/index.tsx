import { Head, router, usePage } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    TablePagination,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/recruitment/components/confirm-dialog';
import { PostingDetailDialog } from '@/features/recruitment/components/posting-detail-dialog';
import { PostingFormDialog } from '@/features/recruitment/components/posting-form-dialog';
import { PostingsTable } from '@/features/recruitment/components/postings-table';
import { RecruitmentStatsCards } from '@/features/recruitment/components/recruitment-stats';
import {
    DEFAULT_FILTERS,
    STATUS_FILTERS,
} from '@/features/recruitment/constants';
import { usePostingsFilters } from '@/features/recruitment/hooks/use-postings-filters';
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

/**
 * Recruitment, first level: every job posting as one table. A row opens that
 * posting's pipeline of candidates.
 */
export default function RecruitmentIndex() {
    const { postings, stats, options, can, filters } =
        usePage<PostingsPageProps>().props;
    const table = usePostingsFilters(filters);

    const [formPosting, setFormPosting] = useState<ManagedPosting | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [detailPosting, setDetailPosting] = useState<ManagedPosting | null>(
        null,
    );
    const [detailOpen, setDetailOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const filtered =
        filters.search !== '' ||
        filters.status !== DEFAULT_FILTERS.status ||
        filters.department !== null;

    // The export carries the table's current filters, so CSV = what you see.
    const exportUrl = `${recruitmentRoutes.export}${
        typeof window !== 'undefined' ? window.location.search : ''
    }`;

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

            <PageBody>
                <PageHeader
                    title="Recruitment"
                    description="Post vacancies, track applicants through the hiring pipeline, and hire."
                />

                <RecruitmentStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={filtered}
                        onReset={table.reset}
                        actions={
                            <>
                                {can.export && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={exportUrl}>
                                            <Download className="size-4" />
                                            Export
                                        </a>
                                    </Button>
                                )}
                                {can.create && (
                                    <Button size="sm" onClick={openCreate}>
                                        <Plus className="size-4" />
                                        New posting
                                    </Button>
                                )}
                            </>
                        }
                    >
                        <SearchInput
                            value={filters.search}
                            onSearch={table.setSearch}
                            placeholder="Search postings…"
                            label="Search postings"
                        />
                        <FilterSelect
                            label="Filter by department"
                            value={
                                filters.department
                                    ? String(filters.department)
                                    : 'all'
                            }
                            onChange={(value) =>
                                table.setDepartment(
                                    value === 'all' ? null : Number(value),
                                )
                            }
                            options={[
                                { value: 'all', label: 'All departments' },
                                ...options.departments.map((department) => ({
                                    value: String(department.id),
                                    label: department.name,
                                })),
                            ]}
                            className="w-44"
                        />
                        <FilterSelect
                            label="Filter by status"
                            value={filters.status}
                            onChange={table.setStatus}
                            options={STATUS_FILTERS}
                            className="w-36"
                        />
                    </ListToolbar>

                    <PostingsTable
                        postings={postings.data}
                        filters={filters}
                        can={can}
                        filtered={filtered}
                        onToggleSort={table.toggleSort}
                        onCreate={openCreate}
                        onView={openDetail}
                        onOpen={openPipeline}
                        onEdit={openEdit}
                        onStatus={setStatusFor}
                        onDelete={remove}
                    />

                    <TablePagination
                        meta={postings.meta}
                        perPage={filters.per_page}
                        onPage={table.setPage}
                        onPerPage={table.setPerPage}
                    />
                </div>
            </PageBody>

            <PostingDetailDialog
                posting={detailPosting}
                open={detailOpen}
                can={can}
                onOpenChange={setDetailOpen}
                onOpenPipeline={openPipeline}
                onEdit={openEdit}
            />

            <PostingFormDialog
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
    breadcrumbs: [{ title: 'Recruitment', href: recruitmentRoutes.index }],
};

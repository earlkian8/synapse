import { Head, Link, usePage } from '@inertiajs/react';
import { Download, Plus, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { removeAward } from '@/features/awards/api';
import { AwardStatsCards } from '@/features/awards/components/award-stats';
import { AwardsTable } from '@/features/awards/components/awards-table';
import { GiveAwardDialog } from '@/features/awards/components/give-award-dialog';
import { awardsRoutes } from '@/features/awards/routes';
import type {
    AwardsIndexPageProps,
    EmployeeAward,
} from '@/features/awards/types';

export default function AwardsIndex() {
    const { awards, types, employees, stats, can } =
        usePage<AwardsIndexPageProps>().props;

    const [giveOpen, setGiveOpen] = useState(false);
    const [edit, setEdit] = useState<EmployeeAward | null>(null);
    const [remove, setRemove] = useState<EmployeeAward | null>(null);
    const [processing, setProcessing] = useState(false);
    const [typeFilter, setTypeFilter] = useState('all');
    const [search, setSearch] = useState('');

    // Distinct award types present in the feed, for the filter.
    const filterTypes = useMemo(() => {
        const map = new Map<number, string>();

        for (const award of awards) {
            if (award.award_type) {
                map.set(award.award_type.id, award.award_type.name);
            }
        }

        return [...map.entries()].map(([id, name]) => ({ id, name }));
    }, [awards]);

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return awards.filter((award) => {
            if (
                typeFilter !== 'all' &&
                String(award.award_type?.id) !== typeFilter
            ) {
                return false;
            }

            if (
                needle !== '' &&
                !award.employee?.full_name.toLowerCase().includes(needle)
            ) {
                return false;
            }

            return true;
        });
    }, [awards, typeFilter, search]);

    const page = useClientPagination(filtered, `${typeFilter}|${search}`);

    const openGive = () => {
        setEdit(null);
        setGiveOpen(true);
    };

    const openEdit = (award: EmployeeAward) => {
        setEdit(award);
        setGiveOpen(true);
    };

    const confirmRemove = () => {
        if (!remove) {
            return;
        }

        removeAward(remove.id, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemove(null);
            },
        });
    };

    return (
        <>
            <Head title="Awards & Recognition" />

            <PageBody>
                <PageHeader
                    title="Awards & Recognition"
                    description="Celebrate great work — the organisation's recognition feed."
                    actions={
                        can.manage && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={awardsRoutes.nominations}>
                                    <Sparkles className="size-4" />
                                    Nomination board
                                </Link>
                            </Button>
                        )
                    }
                />

                <AwardStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={search !== '' || typeFilter !== 'all'}
                        onReset={() => {
                            setSearch('');
                            setTypeFilter('all');
                        }}
                        summary={
                            filtered.length !== awards.length &&
                            `${filtered.length} of ${awards.length}`
                        }
                        actions={
                            <>
                                {awards.length > 0 && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={awardsRoutes.export}>
                                            <Download className="size-4" />
                                            Export
                                        </a>
                                    </Button>
                                )}
                                {can.manage && (
                                    <Button size="sm" onClick={openGive}>
                                        <Plus className="size-4" />
                                        Give recognition
                                    </Button>
                                )}
                            </>
                        }
                    >
                        <SearchInput
                            value={search}
                            onSearch={setSearch}
                            delay={0}
                            placeholder="Search employee…"
                            label="Search recognitions by employee"
                        />
                        <FilterSelect
                            label="Filter by award type"
                            value={typeFilter}
                            onChange={setTypeFilter}
                            options={[
                                { value: 'all', label: 'All award types' },
                                ...filterTypes.map((t) => ({
                                    value: String(t.id),
                                    label: t.name,
                                })),
                            ]}
                            className="w-48"
                        />
                    </ListToolbar>

                    <AwardsTable
                        awards={page.rows}
                        canManage={can.manage}
                        filtered={awards.length > 0}
                        onEdit={openEdit}
                        onRemove={setRemove}
                    />

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

            <GiveAwardDialog
                open={giveOpen}
                onOpenChange={setGiveOpen}
                types={types}
                employees={employees}
                award={edit}
            />

            <ConfirmDialog
                open={remove !== null}
                onOpenChange={(open) => !open && setRemove(null)}
                title="Remove recognition?"
                description={`This award for ${remove?.employee?.full_name ?? 'this employee'} will be removed.`}
                confirmLabel="Remove"
                destructive
                processing={processing}
                onConfirm={confirmRemove}
            />
        </>
    );
}

AwardsIndex.layout = {
    breadcrumbs: [{ title: 'Awards', href: '/awards' }],
};

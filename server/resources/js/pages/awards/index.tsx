import { Head, usePage } from '@inertiajs/react';
import { Award, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { removeAward } from '@/features/awards/api';
import { AwardStatsCards } from '@/features/awards/components/award-stats';
import { AwardTypeBadge } from '@/features/awards/components/award-type-badge';
import { GiveAwardDialog } from '@/features/awards/components/give-award-dialog';
import { formatDate } from '@/features/awards/constants';
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

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Awards &amp; Recognition
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Celebrate great work — the organisation's
                            recognition feed.
                        </p>
                    </div>
                    {can.manage && (
                        <Button size="sm" onClick={openGive}>
                            <Plus className="size-4" />
                            Give recognition
                        </Button>
                    )}
                </div>

                <AwardStatsCards stats={stats} />

                {awards.length === 0 ? (
                    <EmptyState canManage={can.manage} />
                ) : (
                    <div className="flex flex-col gap-3">
                        {/* Filters */}
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <div className="relative sm:max-w-xs sm:flex-1">
                                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search employee…"
                                    className="pl-8"
                                />
                            </div>
                            <Select
                                value={typeFilter}
                                onValueChange={setTypeFilter}
                            >
                                <SelectTrigger className="sm:w-56">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All award types
                                    </SelectItem>
                                    {filterTypes.map((t) => (
                                        <SelectItem
                                            key={t.id}
                                            value={String(t.id)}
                                        >
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {filtered.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                                No recognitions match these filters.
                            </p>
                        ) : (
                            <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                                <ul className="divide-y divide-border">
                                    {filtered.map((award) => (
                                        <AwardRow
                                            key={award.id}
                                            award={award}
                                            canManage={can.manage}
                                            onEdit={() => openEdit(award)}
                                            onRemove={() => setRemove(award)}
                                        />
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </div>

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

function AwardRow({
    award,
    canManage,
    onEdit,
    onRemove,
}: {
    award: EmployeeAward;
    canManage: boolean;
    onEdit: () => void;
    onRemove: () => void;
}) {
    return (
        <li className="flex items-start gap-3 px-4 py-3">
            <PersonAvatar
                name={award.employee?.full_name ?? 'Unknown'}
                initials={award.employee?.initials ?? '?'}
                photo={award.employee?.photo}
                className="size-9"
            />
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-medium">
                        {award.employee?.full_name ?? 'Unknown employee'}
                    </p>
                    {award.award_type && (
                        <AwardTypeBadge
                            name={award.award_type.name}
                            color={award.award_type.color}
                        />
                    )}
                </div>
                {award.reason && (
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {award.reason}
                    </p>
                )}
                <p className="mt-0.5 text-xs text-muted-foreground tabular-nums">
                    {formatDate(award.awarded_on)}
                    {award.granted_by ? ` · by ${award.granted_by.name}` : ''}
                </p>
            </div>
            {canManage && (
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        onClick={onEdit}
                        aria-label="Edit recognition"
                    >
                        <Pencil className="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-muted-foreground hover:text-destructive"
                        onClick={onRemove}
                        aria-label="Remove recognition"
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            )}
        </li>
    );
}

function EmptyState({ canManage }: { canManage: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Award className="size-5" />
            </span>
            <p className="text-sm font-medium">No recognitions yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                {canManage
                    ? 'Add award types under Company Setup → Award Types, then give recognition here.'
                    : 'No recognitions have been given yet.'}
            </p>
        </div>
    );
}

AwardsIndex.layout = {
    breadcrumbs: [{ title: 'Awards', href: '/awards' }],
};

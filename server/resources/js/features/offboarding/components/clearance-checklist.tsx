import { Building2, CheckCheck } from 'lucide-react';
import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { UNASSIGNED_DEPARTMENT } from '../constants';
import type { ClearanceItem, ClearanceStatus } from '../types';
import { ClearanceItemRow } from './clearance-item-row';

type Props = {
    items: ClearanceItem[];
    canManage: boolean;
    onToggle: (item: ClearanceItem, status: ClearanceStatus) => void;
    onEdit: (item: ClearanceItem) => void;
    onDelete: (item: ClearanceItem) => void;
    /** Sign off a whole department group (null = the unassigned group). */
    onClearGroup?: (departmentId: number | null, name: string) => void;
};

type Group = {
    key: string;
    name: string;
    departmentId: number | null;
    items: ClearanceItem[];
};

/**
 * The clearance checklist, grouped by the department responsible for each
 * sign-off (unassigned items grouped last) — the offboarding analogue of the
 * category-grouped onboarding checklist.
 */
export function ClearanceChecklist({
    items,
    canManage,
    onToggle,
    onEdit,
    onDelete,
    onClearGroup,
}: Props) {
    const groups = useMemo(() => {
        const byDepartment = new Map<string, Group>();

        for (const item of items) {
            const key = item.department
                ? `d${item.department.id}`
                : 'unassigned';
            const name = item.department?.name ?? UNASSIGNED_DEPARTMENT;
            const group = byDepartment.get(key) ?? {
                key,
                name,
                departmentId: item.department?.id ?? null,
                items: [],
            };
            group.items.push(item);
            byDepartment.set(key, group);
        }

        // Departments alphabetically, with Unassigned always last.
        return [...byDepartment.values()].sort((a, b) => {
            if (a.key === 'unassigned') {
                return 1;
            }

            if (b.key === 'unassigned') {
                return -1;
            }

            return a.name.localeCompare(b.name);
        });
    }, [items]);

    if (items.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-12 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                No clearance items yet. Add the first sign-off to get started.
            </div>
        );
    }

    return (
        <div className="space-y-5">
            {groups.map((group) => {
                const cleared = group.items.filter((i) => i.is_cleared).length;
                const pending = group.items.filter(
                    (i) => i.status === 'pending',
                ).length;

                return (
                    <div
                        key={group.key}
                        className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
                    >
                        <div className="flex items-center gap-2.5 border-b border-border px-3 py-2.5">
                            <span className="flex size-7 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <Building2 className="size-4" />
                            </span>
                            <span className="text-sm font-semibold">
                                {group.name}
                            </span>
                            <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                {cleared}/{group.items.length}
                            </span>
                            {canManage && onClearGroup && pending > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 text-muted-foreground hover:text-foreground"
                                    onClick={() =>
                                        onClearGroup(
                                            group.departmentId,
                                            group.name,
                                        )
                                    }
                                >
                                    <CheckCheck className="size-4" />
                                    Clear all
                                    <span className="tabular-nums">
                                        ({pending})
                                    </span>
                                </Button>
                            )}
                        </div>
                        <div className="divide-y divide-border">
                            {group.items.map((item) => (
                                <ClearanceItemRow
                                    key={item.id}
                                    item={item}
                                    canManage={canManage}
                                    onToggle={onToggle}
                                    onEdit={onEdit}
                                    onDelete={onDelete}
                                />
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

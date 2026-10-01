import { Network } from 'lucide-react';
import { useMemo } from 'react';
import type { Department } from '../types';
import { DepartmentNode } from './department-node';
import type { NodeHandlers } from './department-node';

type Props = NodeHandlers & {
    departments: Department[];
    search: string;
    canManage: boolean;
};

export function DepartmentTree({
    departments,
    search,
    canManage,
    ...handlers
}: Props) {
    const term = search.trim().toLowerCase();

    const { roots, childrenMap, matches } = useMemo(() => {
        const ids = new Set(departments.map((d) => d.id));
        const childrenMap = new Map<number, Department[]>();

        for (const department of departments) {
            // A department whose parent is missing (e.g. archived) is treated as a root.
            if (
                department.parent_id !== null &&
                ids.has(department.parent_id)
            ) {
                const siblings = childrenMap.get(department.parent_id) ?? [];
                siblings.push(department);
                childrenMap.set(department.parent_id, siblings);
            }
        }

        const roots = departments.filter(
            (d) => d.parent_id === null || !ids.has(d.parent_id),
        );

        const matches = term
            ? departments.filter(
                  (d) =>
                      d.name.toLowerCase().includes(term) ||
                      d.code.toLowerCase().includes(term),
              )
            : [];

        return { roots, childrenMap, matches };
    }, [departments, term]);

    if (departments.length === 0) {
        return <EmptyState />;
    }

    // Search mode: a flat list of matches (hierarchy is hard to read when filtered).
    if (term) {
        if (matches.length === 0) {
            return (
                <div className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-12 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                    No departments match “{search}”.
                </div>
            );
        }

        return (
            <div className="space-y-1.5">
                {matches.map((department) => (
                    <DepartmentNode
                        key={department.id}
                        department={department}
                        childrenMap={childrenMap}
                        depth={0}
                        canManage={canManage}
                        flat
                        {...handlers}
                    />
                ))}
            </div>
        );
    }

    return (
        <div className="space-y-1.5">
            {roots.map((department) => (
                <DepartmentNode
                    key={department.id}
                    department={department}
                    childrenMap={childrenMap}
                    depth={0}
                    canManage={canManage}
                    {...handlers}
                />
            ))}
        </div>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Network className="size-5" />
            </span>
            <p className="text-sm font-medium">No departments yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Add your first department to start building the org structure.
            </p>
        </div>
    );
}

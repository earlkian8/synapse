import {
    Archive,
    Briefcase,
    Building2,
    ChevronRight,
    FolderPlus,
    MoreHorizontal,
    Pencil,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { Department } from '../types';

export type NodeHandlers = {
    onOpen: (department: Department) => void;
    onEdit: (department: Department) => void;
    onAddSub: (parent: Department) => void;
    onArchive: (department: Department) => void;
};

type Props = NodeHandlers & {
    department: Department;
    childrenMap: Map<number, Department[]>;
    depth: number;
    canManage: boolean;
    /** Flat mode (search results) — hide the expander and nesting. */
    flat?: boolean;
};

export function DepartmentNode({
    department,
    childrenMap,
    depth,
    canManage,
    flat = false,
    ...handlers
}: Props) {
    const children = flat ? [] : (childrenMap.get(department.id) ?? []);
    const hasChildren = children.length > 0;
    const [expanded, setExpanded] = useState(true);

    return (
        <div>
            <div
                className="group flex items-center gap-2 rounded-lg border border-sidebar-border/70 bg-card px-2.5 py-2 shadow-sm transition-colors hover:border-[#0ABFBF]/40 dark:border-sidebar-border"
                style={{ marginLeft: flat ? 0 : depth * 22 }}
            >
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => setExpanded((v) => !v)}
                        className="rounded p-0.5 text-muted-foreground hover:bg-muted"
                        aria-label={expanded ? 'Collapse' : 'Expand'}
                    >
                        <ChevronRight
                            className={cn(
                                'size-4 transition-transform',
                                expanded && 'rotate-90',
                            )}
                        />
                    </button>
                ) : (
                    <span className="w-5" />
                )}

                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-[#0F2044]/5 text-[#0F2044] dark:bg-white/5 dark:text-white">
                    <Building2 className="size-4" />
                </span>

                <button
                    type="button"
                    onClick={() => handlers.onOpen(department)}
                    className="min-w-0 flex-1 text-left"
                >
                    <span className="flex items-center gap-2">
                        <span className="truncate text-sm font-medium group-hover:text-[#0ABFBF]">
                            {department.name}
                        </span>
                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground">
                            {department.code}
                        </span>
                    </span>
                    {department.head ? (
                        <span className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <PersonAvatar
                                name={department.head.full_name}
                                initials={department.head.initials}
                                photo={department.head.photo}
                                className="size-5 rounded-md ring-0"
                                fallbackClassName="text-[8px]"
                            />
                            <span className="truncate">
                                {department.head.full_name}
                            </span>
                        </span>
                    ) : (
                        <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                            No head assigned
                        </span>
                    )}
                </button>

                <div className="hidden items-center gap-3 pr-1 text-[11px] text-muted-foreground sm:flex">
                    <span className="inline-flex items-center gap-1">
                        <Users className="size-3.5" />
                        {department.employees_count ?? 0}
                    </span>
                    <span className="inline-flex items-center gap-1">
                        <Briefcase className="size-3.5" />
                        {department.positions_count ?? 0}
                    </span>
                </div>

                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                                aria-label="Department actions"
                            >
                                <MoreHorizontal className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            <DropdownMenuItem
                                onSelect={() => handlers.onEdit(department)}
                            >
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => handlers.onAddSub(department)}
                            >
                                <FolderPlus className="size-4" />
                                Add sub-department
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => handlers.onArchive(department)}
                            >
                                <Archive className="size-4" />
                                Archive
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            {hasChildren && expanded && (
                <div className="mt-1.5 space-y-1.5">
                    {children.map((child) => (
                        <DepartmentNode
                            key={child.id}
                            department={child}
                            childrenMap={childrenMap}
                            depth={depth + 1}
                            canManage={canManage}
                            {...handlers}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

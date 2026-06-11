import {
    Archive,
    Briefcase,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
    UserCog,
    Users,
} from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { Department, DepartmentHead, Position } from '../types';

type Props = {
    department: Department | null;
    canManage: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (department: Department) => void;
    onArchive: (department: Department) => void;
    onAddPosition: (department: Department) => void;
    onEditPosition: (position: Position) => void;
    onDeletePosition: (position: Position) => void;
};

export function DepartmentDetailSheet({
    department,
    canManage,
    open,
    onOpenChange,
    onEdit,
    onArchive,
    onAddPosition,
    onEditPosition,
    onDeletePosition,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
            >
                {department && (
                    <>
                        <SheetHeader className="border-b border-border px-6 py-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <SheetTitle className="flex items-center gap-2">
                                        <span className="truncate">
                                            {department.name}
                                        </span>
                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground">
                                            {department.code}
                                        </span>
                                    </SheetTitle>
                                    {department.description && (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {department.description}
                                        </p>
                                    )}
                                </div>
                                {canManage && (
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => onEdit(department)}
                                        >
                                            <Pencil className="size-4" />
                                            Edit
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className="size-9 text-muted-foreground hover:text-destructive"
                                            onClick={() => onArchive(department)}
                                            aria-label="Archive department"
                                        >
                                            <Archive className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </SheetHeader>

                        <div className="space-y-6 px-6 py-6">
                            <div className="grid grid-cols-2 gap-4">
                                <HeadMeta head={department.head} />
                                <Meta
                                    icon={<Briefcase className="size-4" />}
                                    label="Parent"
                                    value={department.parent?.name ?? 'Top level'}
                                />
                                <Meta
                                    icon={<Users className="size-4" />}
                                    label="Employees"
                                    value={String(
                                        department.employees_count ?? 0,
                                    )}
                                />
                                <Meta
                                    icon={<Briefcase className="size-4" />}
                                    label="Positions"
                                    value={String(
                                        department.positions?.length ??
                                            department.positions_count ??
                                            0,
                                    )}
                                />
                            </div>

                            <div>
                                <div className="mb-2 flex items-center justify-between">
                                    <h3 className="text-sm font-semibold">
                                        Positions
                                    </h3>
                                    {canManage && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                onAddPosition(department)
                                            }
                                        >
                                            <Plus className="size-4" />
                                            Add
                                        </Button>
                                    )}
                                </div>

                                {department.positions &&
                                department.positions.length > 0 ? (
                                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                        {department.positions.map((position) => (
                                            <PositionRow
                                                key={position.id}
                                                position={position}
                                                canManage={canManage}
                                                onEdit={onEditPosition}
                                                onDelete={onDeletePosition}
                                            />
                                        ))}
                                    </div>
                                ) : (
                                    <p className="rounded-xl border border-dashed border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                                        No positions in this department yet.
                                    </p>
                                )}
                            </div>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function PositionRow({
    position,
    canManage,
    onEdit,
    onDelete,
}: {
    position: Position;
    canManage: boolean;
    onEdit: (position: Position) => void;
    onDelete: (position: Position) => void;
}) {
    return (
        <div className="flex items-center gap-3 px-3 py-2.5">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{position.title}</p>
                <p className="text-xs text-muted-foreground">
                    {formatBand(
                        position.salary_grade_min,
                        position.salary_grade_max,
                    )}
                    {(position.employees_count ?? 0) > 0 &&
                        ` · ${position.employees_count} employee${position.employees_count === 1 ? '' : 's'}`}
                </p>
            </div>
            {canManage && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                            aria-label="Position actions"
                        >
                            <MoreHorizontal className="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-36">
                        <DropdownMenuItem onSelect={() => onEdit(position)}>
                            <Pencil className="size-4" />
                            Edit
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onDelete(position)}
                        >
                            <Trash2 className="size-4" />
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}

function HeadMeta({ head }: { head: DepartmentHead | null }) {
    return (
        <div className="rounded-lg border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border">
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <UserCog className="size-4" />
                Head
            </div>
            {head ? (
                <div className="mt-1.5 flex items-center gap-2">
                    <PersonAvatar
                        name={head.full_name}
                        initials={head.initials}
                        photo={head.photo}
                        className="size-7"
                        fallbackClassName="text-[10px]"
                    />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium">
                            {head.full_name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {head.employee_no}
                        </p>
                    </div>
                </div>
            ) : (
                <p className="mt-1 text-sm font-medium">Not assigned</p>
            )}
        </div>
    );
}

function Meta({
    icon,
    label,
    value,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-lg border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border">
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {icon}
                {label}
            </div>
            <p className="mt-1 truncate text-sm font-medium">{value}</p>
        </div>
    );
}

function formatBand(min: string | null, max: string | null): string {
    if (!min && !max) {
        return 'No salary band';
    }

    const fmt = (v: string) =>
        `₱${Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

    if (min && max) {
        return `${fmt(min)} – ${fmt(max)}`;
    }

    return fmt((min ?? max)!);
}

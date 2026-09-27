import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardList,
    ListChecks,
    MoreHorizontal,
    Rocket,
    Settings2,
    Users,
} from 'lucide-react';
import type { MouseEvent } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { EMPLOYMENT_TYPE_LABELS } from '../constants';
import { onboardingRoutes } from '../routes';
import type { OnboardingPermissions, ProgramOverviewRow } from '../types';
import { ProgressBar } from './progress-bar';

type Props = {
    programs: ProgramOverviewRow[];
    can: OnboardingPermissions;
    searching: boolean;
    onStart: (programId: number) => void;
};

/**
 * The onboarding overview: one row per program — who it applies to, how many
 * people it is onboarding, how far along they are and what has slipped. A row
 * opens the people it covers.
 */
export function ProgramsOverviewTable({
    programs,
    can,
    searching,
    onStart,
}: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Table>
                <TableHeader className="bg-muted/40">
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="h-9 pl-4">Program</TableHead>
                        <TableHead className="h-9">Applies to</TableHead>
                        <TableHead className="h-9 text-right">Tasks</TableHead>
                        <TableHead className="h-9 text-right">
                            Onboarding
                        </TableHead>
                        <TableHead className="h-9 text-right">
                            Completed
                        </TableHead>
                        <TableHead className="h-9 w-44">Progress</TableHead>
                        <TableHead className="h-9 text-right">
                            Overdue
                        </TableHead>
                        <TableHead className="h-9 w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {programs.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={8} className="py-12">
                                <Empty searching={searching} />
                            </TableCell>
                        </TableRow>
                    )}

                    {programs.map((program) => (
                        <ProgramRow
                            key={program.hashid ?? 'unassigned'}
                            program={program}
                            can={can}
                            onStart={onStart}
                        />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function ProgramRow({
    program,
    can,
    onStart,
}: {
    program: ProgramOverviewRow;
    can: OnboardingPermissions;
    onStart: (programId: number) => void;
}) {
    const href = onboardingRoutes.program(program.hashid);
    const unassigned = program.hashid === null;
    const targeting = [
        program.department?.name,
        program.employment_type
            ? EMPLOYMENT_TYPE_LABELS[program.employment_type]
            : null,
    ].filter(Boolean);

    // The whole row opens the program; its links and menu keep their own clicks.
    const open = (event: MouseEvent<HTMLTableRowElement>) => {
        if (
            (event.target as HTMLElement).closest(
                'a, button, [role="menuitem"]',
            )
        ) {
            return;
        }

        router.visit(href);
    };

    return (
        <TableRow
            onClick={open}
            className={cn(
                'cursor-pointer',
                !program.is_active && 'text-muted-foreground',
            )}
        >
            <TableCell className="py-2 pl-4">
                <div className="flex min-w-0 items-center gap-2.5">
                    <span
                        className={cn(
                            'flex size-8 shrink-0 items-center justify-center rounded-lg',
                            unassigned
                                ? 'bg-muted text-muted-foreground'
                                : 'bg-[#0ABFBF]/10 text-[#0ABFBF]',
                        )}
                    >
                        <ClipboardList className="size-4" />
                    </span>
                    <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                            <Link
                                href={href}
                                className="truncate text-sm font-medium text-foreground hover:text-[#0ABFBF]"
                            >
                                {program.name}
                            </Link>
                            {program.is_default && (
                                <Tag className="border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                    Default
                                </Tag>
                            )}
                            {!program.is_active && (
                                <Tag className="border-border bg-muted text-muted-foreground">
                                    Inactive
                                </Tag>
                            )}
                        </div>
                        {program.description && (
                            <p className="line-clamp-1 max-w-sm text-xs text-muted-foreground">
                                {program.description}
                            </p>
                        )}
                    </div>
                </div>
            </TableCell>
            <TableCell className="py-2 text-sm text-muted-foreground">
                {unassigned
                    ? '—'
                    : targeting.length > 0
                      ? targeting.join(' · ')
                      : 'All new hires'}
            </TableCell>
            <TableCell className="py-2 text-right text-sm text-muted-foreground tabular-nums">
                {program.tasks_count === null ? (
                    '—'
                ) : (
                    <span className="inline-flex items-center gap-1">
                        <ListChecks className="size-3.5" />
                        {program.tasks_count}
                    </span>
                )}
            </TableCell>
            <TableCell className="py-2 text-right text-sm font-medium tabular-nums">
                {program.cases.active}
            </TableCell>
            <TableCell className="py-2 text-right text-sm text-muted-foreground tabular-nums">
                {program.cases.completed}
            </TableCell>
            <TableCell className="py-2">
                {program.progress === null ? (
                    <span className="text-xs text-muted-foreground">
                        Nobody in progress
                    </span>
                ) : (
                    <div className="flex items-center gap-2">
                        <ProgressBar
                            percent={program.progress}
                            className="w-24"
                        />
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {program.progress}%
                        </span>
                    </div>
                )}
            </TableCell>
            <TableCell className="py-2 text-right text-sm tabular-nums">
                {program.overdue > 0 ? (
                    <span className="inline-flex items-center gap-1 font-medium text-rose-600 dark:text-rose-400">
                        <AlertTriangle className="size-3.5" />
                        {program.overdue}
                    </span>
                ) : (
                    <span className="text-muted-foreground">0</span>
                )}
            </TableCell>
            <TableCell className="py-2 pr-4 text-right">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className="ml-auto rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                            aria-label={`Actions for ${program.name}`}
                        >
                            <MoreHorizontal className="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuItem asChild>
                            <Link href={href}>
                                <Users className="size-4" />
                                View employees
                            </Link>
                        </DropdownMenuItem>
                        {can.manage &&
                            program.id !== null &&
                            program.is_active && (
                                <DropdownMenuItem
                                    onSelect={() => onStart(program.id!)}
                                >
                                    <Rocket className="size-4" />
                                    Start onboarding here
                                </DropdownMenuItem>
                            )}
                        {can.managePrograms && program.hashid !== null && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link href={onboardingRoutes.programs}>
                                        <Settings2 className="size-4" />
                                        Edit in Company Setup
                                    </Link>
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </TableCell>
        </TableRow>
    );
}

function Tag({
    className,
    children,
}: {
    className: string;
    children: React.ReactNode;
}) {
    return (
        <span
            className={cn(
                'shrink-0 rounded-full border px-1.5 py-px text-[10px] font-medium',
                className,
            )}
        >
            {children}
        </span>
    );
}

function Empty({ searching }: { searching: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 text-center">
            <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                <ClipboardList className="size-5 text-muted-foreground" />
            </span>
            <p className="text-sm font-medium">
                {searching ? 'No programs match' : 'No onboarding programs yet'}
            </p>
            <p className="max-w-xs text-sm text-muted-foreground">
                {searching
                    ? 'Try another name, or clear the search.'
                    : 'Create a program in Company Setup — every new hire’s checklist is seeded from one.'}
            </p>
        </div>
    );
}

import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardList,
    ListChecks,
    Rocket,
    Settings2,
    Users,
} from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
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
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Program</TableHead>
                        <TableHead>Applies to</TableHead>
                        <TableHead className="text-right">Tasks</TableHead>
                        <TableHead className="text-right">Onboarding</TableHead>
                        <TableHead className="text-right">Completed</TableHead>
                        <TableHead className="w-44">Progress</TableHead>
                        <TableHead className="text-right">Overdue</TableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {programs.length === 0 && (
                        <EmptyTableRow
                            colSpan={8}
                            icon={ClipboardList}
                            title={
                                searching
                                    ? 'No programs match'
                                    : 'No onboarding programs yet'
                            }
                            description={
                                searching
                                    ? 'Try another name, or clear the search.'
                                    : 'Create a program in Company Setup — every new hire’s checklist is seeded from one.'
                            }
                        />
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
            </DataTable>
        </TableCard>
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
    const opens = rowOpens(href);
    const targeting = [
        program.department?.name,
        program.employment_type
            ? EMPLOYMENT_TYPE_LABELS[program.employment_type]
            : null,
    ].filter(Boolean);

    return (
        <TableRow
            {...opens}
            className={cn(
                opens.className,
                !program.is_active && 'text-muted-foreground',
            )}
        >
            <TableCell>
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
                            <p className="line-clamp-1 max-w-sm text-xs whitespace-normal text-muted-foreground">
                                {program.description}
                            </p>
                        )}
                    </div>
                </div>
            </TableCell>
            <TableCell className="text-sm text-muted-foreground">
                {unassigned
                    ? '—'
                    : targeting.length > 0
                      ? targeting.join(' · ')
                      : 'All new hires'}
            </TableCell>
            <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                {program.tasks_count === null ? (
                    '—'
                ) : (
                    <span className="inline-flex items-center gap-1">
                        <ListChecks className="size-3.5" />
                        {program.tasks_count}
                    </span>
                )}
            </TableCell>
            <TableCell className="text-right text-sm font-medium tabular-nums">
                {program.cases.active}
            </TableCell>
            <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                {program.cases.completed}
            </TableCell>
            <TableCell>
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
            <TableCell className="text-right text-sm tabular-nums">
                {program.overdue > 0 ? (
                    <span className="inline-flex items-center gap-1 font-medium text-rose-600 dark:text-rose-400">
                        <AlertTriangle className="size-3.5" />
                        {program.overdue}
                    </span>
                ) : (
                    <span className="text-muted-foreground">0</span>
                )}
            </TableCell>
            <TableCell className="text-right">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <RowMenuTrigger label={`Actions for ${program.name}`} />
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

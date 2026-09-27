import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    Download,
    ListChecks,
    RotateCcw,
    Trash2,
    XCircle,
} from 'lucide-react';
import { RowMenuTrigger } from '@/components/data-table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { offboardingRoutes } from '../routes';
import type { OffboardingCase } from '../types';

export type CaseRowHandlers = {
    onComplete: (item: OffboardingCase) => void;
    onReopen: (item: OffboardingCase) => void;
    onCancel: (item: OffboardingCase) => void;
    onDelete: (item: OffboardingCase) => void;
};

/**
 * An exit's actions from the offboarding table: open the clearance checklist,
 * download its sheet, and — for someone who manages offboarding — move the exit
 * through its lifecycle or remove it.
 */
export function CaseRowActions({
    item,
    canManage,
    onComplete,
    onReopen,
    onCancel,
    onDelete,
}: CaseRowHandlers & { item: OffboardingCase; canManage: boolean }) {
    const name = item.employee?.full_name ?? 'this employee';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <RowMenuTrigger label={`Actions for ${name}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuItem asChild>
                    <Link href={offboardingRoutes.show(item.hashid)}>
                        <ListChecks className="size-4" />
                        Open clearance
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={offboardingRoutes.clearanceExport(item.hashid)}>
                        <Download className="size-4" />
                        Export clearance sheet
                    </a>
                </DropdownMenuItem>

                {canManage && (
                    <>
                        <DropdownMenuSeparator />
                        {item.is_active ? (
                            <DropdownMenuItem onSelect={() => onComplete(item)}>
                                <CheckCircle2 className="size-4" />
                                Complete exit
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem onSelect={() => onReopen(item)}>
                                <RotateCcw className="size-4" />
                                Reopen
                            </DropdownMenuItem>
                        )}
                        {item.is_active && (
                            <DropdownMenuItem onSelect={() => onCancel(item)}>
                                <XCircle className="size-4" />
                                Cancel offboarding
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onDelete(item)}
                        >
                            <Trash2 className="size-4" />
                            Delete
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

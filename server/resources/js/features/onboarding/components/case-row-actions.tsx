import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    ListChecks,
    MoreHorizontal,
    RotateCcw,
    Trash2,
    XCircle,
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { onboardingRoutes } from '../routes';
import type { OnboardingCase } from '../types';

export type CaseRowHandlers = {
    onComplete: (item: OnboardingCase) => void;
    onReopen: (item: OnboardingCase) => void;
    onCancel: (item: OnboardingCase) => void;
    onDelete: (item: OnboardingCase) => void;
};

/**
 * A case's actions from the program's table: open the checklist, and — for
 * someone who manages onboarding — move it through its lifecycle or remove it.
 */
export function CaseRowActions({
    item,
    canManage,
    onComplete,
    onReopen,
    onCancel,
    onDelete,
}: CaseRowHandlers & { item: OnboardingCase; canManage: boolean }) {
    const name = item.employee?.full_name ?? 'this employee';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="ml-auto rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                    aria-label={`Actions for ${name}`}
                >
                    <MoreHorizontal className="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem asChild>
                    <Link href={onboardingRoutes.show(item.hashid)}>
                        <ListChecks className="size-4" />
                        Open checklist
                    </Link>
                </DropdownMenuItem>

                {canManage && (
                    <>
                        {item.is_active ? (
                            <DropdownMenuItem onSelect={() => onComplete(item)}>
                                <CheckCircle2 className="size-4" />
                                Mark complete
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
                                Cancel onboarding
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

import {
    MoreHorizontal,
    MoveRight,
    UserRoundCheck,
    XCircle,
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type {
    Application,
    PipelineStage,
    RecruitmentPermissions,
} from '../types';

type Props = {
    application: Application;
    /** The posting's open-kind stages, in order. */
    openStages: PipelineStage[];
    can: RecruitmentPermissions;
    onMove: (application: Application, stageId: number) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
    align?: 'start' | 'end';
    triggerClassName?: string;
};

/**
 * The Move / Hire / Reject menu for a non-terminal application. Shared by the
 * board card and the pipeline table so both expose the same actions. Callers
 * guard terminal (won/lost) applications — this renders nothing useful for
 * them.
 */
export function ApplicationActionsMenu({
    application,
    openStages,
    can,
    onMove,
    onHire,
    onReject,
    align = 'end',
    triggerClassName,
}: Props) {
    const canHire = can.hire && application.stage_kind === 'open';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted',
                        triggerClassName,
                    )}
                    aria-label="Application actions"
                >
                    <MoreHorizontal className="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align={align} className="w-48">
                {can.managePipeline && (
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            <MoveRight className="size-4" />
                            Move to…
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            {openStages
                                .filter((s) => s.id !== application.stage_id)
                                .map((s) => (
                                    <DropdownMenuItem
                                        key={s.id}
                                        onSelect={() =>
                                            onMove(application, s.id)
                                        }
                                    >
                                        {s.name}
                                    </DropdownMenuItem>
                                ))}
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                )}
                {canHire && (
                    <DropdownMenuItem onSelect={() => onHire(application)}>
                        <UserRoundCheck className="size-4" />
                        Hire
                    </DropdownMenuItem>
                )}
                {can.managePipeline && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onReject(application)}
                        >
                            <XCircle className="size-4" />
                            Reject
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

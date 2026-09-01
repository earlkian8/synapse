import { ChevronRight, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { KIND_DOT } from '../constants';
import type { Pipeline } from '../types';

type Props = {
    pipeline: Pipeline;
    canManage: boolean;
    onEdit: (pipeline: Pipeline) => void;
    onDelete: (pipeline: Pipeline) => void;
};

export function PipelineCard({ pipeline, canManage, onEdit, onDelete }: Props) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-sm font-semibold">
                            {pipeline.name}
                        </span>
                        {pipeline.is_default && (
                            <span className="rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-1.5 py-0.5 text-[10px] font-medium text-[#0ABFBF]">
                                Default
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {pipeline.postings_count}{' '}
                        {pipeline.postings_count === 1 ? 'posting' : 'postings'}
                        {pipeline.created_human
                            ? ` · created ${pipeline.created_human}`
                            : ''}
                    </p>
                </div>

                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                                aria-label="Pipeline actions"
                            >
                                <MoreHorizontal className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                            <DropdownMenuItem onSelect={() => onEdit(pipeline)}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => onDelete(pipeline)}
                            >
                                <Trash2 className="size-4" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            {/* The stages themselves, in order — this is a real sequence, so a
                numbered chip trail earns its keep here. */}
            <div className="flex flex-wrap items-center gap-1 border-t border-border pt-3">
                {pipeline.stages.map((stage, index) => (
                    <span key={stage.id} className="flex items-center gap-1">
                        {index > 0 && (
                            <ChevronRight className="size-3 shrink-0 text-muted-foreground/50" />
                        )}
                        <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium">
                            <span
                                className={cn(
                                    'size-1.5 shrink-0 rounded-full',
                                    KIND_DOT[stage.kind],
                                )}
                            />
                            {stage.name}
                        </span>
                    </span>
                ))}
            </div>
        </div>
    );
}

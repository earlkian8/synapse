import { Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';

type Props = {
    count: number;
    onDelete: () => void;
    onClear: () => void;
};

export function ActivityBulkActionsBar({ count, onDelete, onClear }: Props) {
    return (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-[#0ABFBF]/30 bg-[#0ABFBF]/5 px-3 py-2 shadow-sm">
            <span className="inline-flex items-center gap-2 px-1 text-sm font-medium">
                <span className="flex size-6 items-center justify-center rounded-full bg-[#0ABFBF] text-xs font-semibold text-white tabular-nums">
                    {count}
                </span>
                selected
            </span>

            <span className="mx-1 h-5 w-px bg-border" />

            <Button
                variant="ghost"
                size="sm"
                className="text-destructive hover:text-destructive"
                onClick={onDelete}
            >
                <Trash2 className="size-4" />
                Delete
            </Button>

            <Button
                variant="ghost"
                size="sm"
                onClick={onClear}
                className="ml-auto text-muted-foreground"
            >
                <X className="size-4" />
                Clear
            </Button>
        </div>
    );
}

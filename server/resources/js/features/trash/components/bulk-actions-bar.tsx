import { RotateCcw, Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { TrashItem } from '../types';

type Props = {
    items: TrashItem[];
    onRestore: () => void;
    onDelete: () => void;
    onClear: () => void;
};

export function BulkActionsBar({ items, onRestore, onDelete, onClear }: Props) {
    const restorable = items.filter((item) => item.can_restore).length;
    const deletable = items.filter((item) => item.can_force_delete).length;

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-[#0ABFBF]/30 bg-[#0ABFBF]/5 px-4 py-2.5">
            <span className="text-sm font-medium">{items.length} selected</span>
            <span className="h-4 w-px bg-border" />

            {restorable > 0 && (
                <Button variant="outline" size="sm" onClick={onRestore}>
                    <RotateCcw className="size-4" />
                    Restore
                    {restorable !== items.length ? ` (${restorable})` : ''}
                </Button>
            )}
            {deletable > 0 && (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={onDelete}
                    className="text-destructive hover:text-destructive"
                >
                    <Trash2 className="size-4" />
                    Delete{deletable !== items.length ? ` (${deletable})` : ''}
                </Button>
            )}

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

import { CircleCheck, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: React.ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    processing?: boolean;
    onConfirm: () => void;
};

/**
 * The module's confirmation step for an action that cannot be shrugged off —
 * archiving an employee, revoking access, deleting a record. Centred, compact, and
 * opened with the cancel button focused so a stray Enter never confirms.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    destructive = false,
    processing = false,
    onConfirm,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="gap-0 p-0 sm:max-w-md">
                <DialogHeader className="gap-0 px-6 pt-6 text-left">
                    <div className="flex items-start gap-3.5 pr-6">
                        <span
                            className={cn(
                                'flex size-10 shrink-0 items-center justify-center rounded-xl',
                                destructive
                                    ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                                    : 'bg-[#0ABFBF]/10 text-[#0a8f8f] dark:text-[#0ABFBF]',
                            )}
                        >
                            {destructive ? (
                                <TriangleAlert className="size-5" />
                            ) : (
                                <CircleCheck className="size-5" />
                            )}
                        </span>
                        <div className="min-w-0 flex-1">
                            <DialogTitle className="text-base leading-snug font-semibold tracking-tight">
                                {title}
                            </DialogTitle>
                            <DialogDescription asChild>
                                <div className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                                    {description}
                                </div>
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="flex flex-col-reverse gap-2 px-6 pt-5 pb-6 sm:flex-row sm:justify-end">
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={onConfirm}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        {confirmLabel}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

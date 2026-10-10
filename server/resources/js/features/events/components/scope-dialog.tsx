import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { EventScope } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** What is being done, e.g. "Archive", "Save changes to". */
    action: string;
    /** e.g. "Weekly standup" */
    title: string;
    /** "3 of 12" — where this date sits in the series. */
    position?: string | null;
    destructive?: boolean;
    processing?: boolean;
    onChoose: (scope: EventScope) => void;
};

/**
 * Asked before a change to one date of a repeating event: does it cover this
 * date alone, or this one and every later date? Earlier dates are never
 * touched.
 */
export function ScopeDialog({
    open,
    onOpenChange,
    action,
    title,
    position,
    destructive = false,
    processing = false,
    onChoose,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {action} “{title}”
                    </DialogTitle>
                    <DialogDescription>
                        This event repeats
                        {position ? ` — this is date ${position}` : ''}. Earlier
                        dates stay as they are either way.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2 sm:justify-between">
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row">
                        <Button
                            variant="outline"
                            onClick={() => onChoose('this')}
                            disabled={processing}
                        >
                            Only this date
                        </Button>
                        <Button
                            variant={destructive ? 'destructive' : 'default'}
                            onClick={() => onChoose('following')}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            This and later dates
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

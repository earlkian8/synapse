import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { offboardingRoutes } from '../routes';
import type { ProgramOption } from '../types';

type Props = {
    caseHashid: string;
    programs: ProgramOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Bulk-add the items of a clearance template to a case's checklist. Items
 * already on the checklist (matched by label) are skipped server-side, so
 * applying a template twice is harmless.
 */
export function ApplyProgramDialog({
    caseHashid,
    programs,
    open,
    onOpenChange,
}: Props) {
    const [programId, setProgramId] = useState('');
    const [processing, setProcessing] = useState(false);

    const apply = () => {
        if (!programId) {
            return;
        }

        router.post(
            offboardingRoutes.applyProgram(caseHashid),
            { offboarding_program_id: Number(programId) },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setProgramId('');
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Add items from a template</DialogTitle>
                    <DialogDescription>
                        Every item in the template is appended to this
                        checklist. Items already on it are skipped.
                    </DialogDescription>
                </DialogHeader>

                <div>
                    <Label className="mb-1.5 block">Clearance template</Label>
                    <Select value={programId} onValueChange={setProgramId}>
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select a template…" />
                        </SelectTrigger>
                        <SelectContent>
                            {programs.map((p) => (
                                <SelectItem key={p.id} value={String(p.id)}>
                                    {p.name}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {p.items_count} item
                                        {p.items_count === 1 ? '' : 's'}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button onClick={apply} disabled={processing || !programId}>
                        {processing && <Spinner />}
                        Add items
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

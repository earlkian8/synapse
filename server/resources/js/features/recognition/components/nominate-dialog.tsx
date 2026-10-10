import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
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
import { recognitionRoutes } from '../routes';
import type { NominatableType, Person } from '../types';
import { ColleaguePicker } from './colleague-picker';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    colleagues: Person[];
    types: NominatableType[];
};

/**
 * Nominate a colleague for an award (ADR 0071). The reason is what HR reads,
 * and — once approved — the award's citation.
 */
export function NominateDialog({
    open,
    onOpenChange,
    colleagues,
    types,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Nominate a colleague</DialogTitle>
                    <DialogDescription>
                        HR reviews every nomination. If it’s approved, your
                        words become the award’s citation, and you’ll hear
                        either way.
                    </DialogDescription>
                </DialogHeader>
                {open && (
                    <NominateForm
                        colleagues={colleagues}
                        types={types}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function NominateForm({
    colleagues,
    types,
    onDone,
}: {
    colleagues: Person[];
    types: NominatableType[];
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        employee_id: null as number | null,
        award_type_id: (types[0]?.id ?? null) as number | null,
        reason: '',
    });
    const type = types.find((t) => t.id === data.award_type_id);

    if (types.length === 0) {
        return (
            <>
                <p className="rounded-md border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                    No award is open to nominations right now.
                </p>
                <DialogFooter>
                    <Button variant="outline" onClick={onDone}>
                        Close
                    </Button>
                </DialogFooter>
            </>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                post(recognitionRoutes.nominate, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="flex flex-col gap-4"
        >
            <div>
                <Label className="mb-1.5 block">Who</Label>
                <ColleaguePicker
                    colleagues={colleagues}
                    value={data.employee_id}
                    onChange={(id) => setData('employee_id', id)}
                    invalid={Boolean(errors.employee_id)}
                />
                <InputError message={errors.employee_id} className="mt-1.5" />
            </div>

            <div>
                <Label className="mb-1.5 block">For</Label>
                <Select
                    value={
                        data.award_type_id
                            ? String(data.award_type_id)
                            : undefined
                    }
                    onValueChange={(v) => setData('award_type_id', Number(v))}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Choose an award" />
                    </SelectTrigger>
                    <SelectContent>
                        {types.map((t) => (
                            <SelectItem key={t.id} value={String(t.id)}>
                                {t.name}
                                {t.points > 0 && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {t.points} points
                                    </span>
                                )}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {type?.description && (
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {type.description}
                    </p>
                )}
                <InputError message={errors.award_type_id} className="mt-1.5" />
            </div>

            <div>
                <Label className="mb-1.5 block" htmlFor="nomination-reason">
                    Why
                </Label>
                <textarea
                    id="nomination-reason"
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    rows={4}
                    maxLength={1000}
                    placeholder="What did they do, and what difference did it make?"
                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                />
                <div className="mt-1 flex justify-between gap-2">
                    <InputError message={errors.reason} />
                    <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                        {data.reason.trim().length < 20
                            ? `${20 - data.reason.trim().length} more to go`
                            : `${data.reason.length}/1000`}
                    </span>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        processing ||
                        data.employee_id === null ||
                        data.reason.trim().length < 20
                    }
                >
                    {processing && <Spinner />}
                    Send nomination
                </Button>
            </DialogFooter>
        </form>
    );
}

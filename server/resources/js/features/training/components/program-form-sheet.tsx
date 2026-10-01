import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { trainingRoutes } from '../routes';
import type { TrainingProgram } from '../types';

type Props = {
    program: TrainingProgram | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function ProgramFormSheet({ program, open, onOpenChange }: Props) {
    const isEditing = Boolean(program);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing
                            ? 'Edit training program'
                            : 'New training program'}
                    </SheetTitle>
                    <SheetDescription>
                        A training program or cohort, with its provider,
                        schedule and seat capacity.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={program?.id ?? 'new'}
                        program={program}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    program,
    onDone,
}: {
    program: TrainingProgram | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(program);
    const { data, setData, post, processing, errors } = useForm({
        name: program?.name ?? '',
        provider: program?.provider ?? '',
        description: program?.description ?? '',
        start_date: program?.start_date ?? '',
        end_date: program?.end_date ?? '',
        capacity: program?.capacity ?? ('' as number | ''),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && program) {
            post(trainingRoutes.update(program.hashid), opts);
        } else {
            post(trainingRoutes.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">
                        Name<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. Leadership Essentials"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">Provider</Label>
                    <Input
                        value={data.provider}
                        onChange={(e) => setData('provider', e.target.value)}
                        placeholder="e.g. Dale Carnegie, In-house"
                    />
                    <InputError message={errors.provider} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label className="mb-1.5 block">Start date</Label>
                        <Input
                            type="date"
                            value={data.start_date ?? ''}
                            onChange={(e) =>
                                setData('start_date', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.start_date}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">End date</Label>
                        <Input
                            type="date"
                            value={data.end_date ?? ''}
                            onChange={(e) =>
                                setData('end_date', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.end_date}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label className="mb-1.5 block">Capacity</Label>
                    <Input
                        type="number"
                        min="1"
                        inputMode="numeric"
                        value={data.capacity}
                        onChange={(e) =>
                            setData(
                                'capacity',
                                e.target.value === ''
                                    ? ''
                                    : Number(e.target.value),
                            )
                        }
                        placeholder="Leave blank for unlimited"
                    />
                    <InputError message={errors.capacity} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">Description</Label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        placeholder="What the program covers (optional)"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    />
                    <InputError
                        message={errors.description}
                        className="mt-1.5"
                    />
                </div>
            </div>

            <SheetFooter className="border-t border-border px-6 py-4">
                <div className="flex w-full items-center justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onDone}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        {isEditing ? 'Save changes' : 'Create'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

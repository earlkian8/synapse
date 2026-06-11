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
import { onboardingRoutes } from '../routes';
import type { OnboardingCase } from '../types';

type Props = {
    case: OnboardingCase;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function CaseSettingsSheet({ case: c, open, onOpenChange }: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>Onboarding details</SheetTitle>
                    <SheetDescription>
                        Set a target completion date and internal notes.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody case={c} onDone={() => onOpenChange(false)} />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    case: c,
    onDone,
}: {
    case: OnboardingCase;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        target_end_date: c.target_end_date ?? '',
        notes: c.notes ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            target_end_date: payload.target_end_date || null,
            notes: payload.notes || null,
        }));

        post(onboardingRoutes.update(c.hashid), {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">Target completion</Label>
                    <Input
                        type="date"
                        value={data.target_end_date ?? ''}
                        onChange={(e) =>
                            setData('target_end_date', e.target.value)
                        }
                    />
                    <InputError
                        message={errors.target_end_date}
                        className="mt-1.5"
                    />
                </div>

                <div>
                    <Label className="mb-1.5 block">Notes</Label>
                    <textarea
                        value={data.notes ?? ''}
                        onChange={(e) => setData('notes', e.target.value)}
                        rows={5}
                        placeholder="Anything the team should know about this onboarding…"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                    <InputError message={errors.notes} className="mt-1.5" />
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
                        Save
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

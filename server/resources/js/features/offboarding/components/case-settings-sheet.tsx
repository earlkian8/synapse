import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { TYPE_OPTIONS } from '../constants';
import { offboardingRoutes } from '../routes';
import type { OffboardingCase, OffboardingType } from '../types';

type Props = {
    case: OffboardingCase;
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
                    <SheetTitle>Exit details</SheetTitle>
                    <SheetDescription>
                        Update the exit type, key dates and reason.
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
    case: OffboardingCase;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        type: c.type,
        notice_date: c.notice_date ?? '',
        last_working_day: c.last_working_day ?? '',
        reason: c.reason ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            notice_date: payload.notice_date || null,
            last_working_day: payload.last_working_day || null,
            reason: payload.reason || null,
        }));

        post(offboardingRoutes.update(c.hashid), {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">Exit type</Label>
                    <Select
                        value={data.type}
                        onValueChange={(v) =>
                            setData('type', v as OffboardingType)
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {TYPE_OPTIONS.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.type} className="mt-1.5" />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label className="mb-1.5 block">Notice date</Label>
                        <Input
                            type="date"
                            value={data.notice_date ?? ''}
                            onChange={(e) =>
                                setData('notice_date', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.notice_date}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Last working day</Label>
                        <Input
                            type="date"
                            value={data.last_working_day ?? ''}
                            onChange={(e) =>
                                setData('last_working_day', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.last_working_day}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label className="mb-1.5 block">Reason</Label>
                    <textarea
                        value={data.reason ?? ''}
                        onChange={(e) => setData('reason', e.target.value)}
                        rows={4}
                        placeholder="Context for the exit (kept internal)…"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                    <InputError message={errors.reason} className="mt-1.5" />
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

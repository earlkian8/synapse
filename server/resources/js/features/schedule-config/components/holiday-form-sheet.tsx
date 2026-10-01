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
import { Switch } from '@/components/ui/switch';
import { HOLIDAY_TYPE_OPTIONS } from '../constants';
import { scheduleConfigRoutes } from '../routes';
import type { Holiday, HolidayType } from '../types';

type Props = {
    holiday: Holiday | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function HolidayFormSheet({ holiday, open, onOpenChange }: Props) {
    const isEditing = Boolean(holiday);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit holiday' : 'New holiday'}
                    </SheetTitle>
                    <SheetDescription>
                        A date in the company calendar. Non-working holidays are
                        not charged as leave.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={holiday?.id ?? 'new'}
                        holiday={holiday}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    holiday,
    onDone,
}: {
    holiday: Holiday | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(holiday);

    const { data, setData, post, processing, errors } = useForm({
        name: holiday?.name ?? '',
        date: holiday?.date ?? '',
        type: (holiday?.type ?? 'regular') as HolidayType,
        is_recurring: holiday?.is_recurring ?? false,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && holiday) {
            post(scheduleConfigRoutes.holidays.update(holiday.hashid), opts);
        } else {
            post(scheduleConfigRoutes.holidays.store, opts);
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
                        placeholder="e.g. New Year's Day"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">
                        Date<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        type="date"
                        value={data.date ?? ''}
                        onChange={(e) => setData('date', e.target.value)}
                        required
                    />
                    <InputError message={errors.date} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">
                        Type<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Select
                        value={data.type}
                        onValueChange={(v) => setData('type', v as HolidayType)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {HOLIDAY_TYPE_OPTIONS.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Regular and special non-working days are days off; a
                        special working day is an ordinary working day.
                    </p>
                    <InputError message={errors.type} className="mt-1.5" />
                </div>

                <div className="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2.5 dark:border-sidebar-border">
                    <div className="pr-3">
                        <Label className="text-sm">Repeats yearly</Label>
                        <p className="text-xs text-muted-foreground">
                            Recurs every year on the same month and day (the
                            year is ignored).
                        </p>
                    </div>
                    <Switch
                        checked={data.is_recurring}
                        onCheckedChange={(v) => setData('is_recurring', v)}
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

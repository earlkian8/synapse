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
import { TYPE_LABELS, toDateTimeLocal } from '../constants';
import { eventRoutes } from '../routes';
import type { EventItem, EventType } from '../types';

const TYPES: EventType[] = ['event', 'meeting'];

type Props = {
    event: EventItem | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function EventFormSheet({ event, open, onOpenChange }: Props) {
    const isEditing = Boolean(event);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit event' : 'New event'}
                    </SheetTitle>
                    <SheetDescription>
                        An event or meeting, with its schedule, location and
                        kind. Invite attendees once it's created.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={event?.id ?? 'new'}
                        event={event}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    event,
    onDone,
}: {
    event: EventItem | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(event);
    const { data, setData, post, processing, errors } = useForm({
        title: event?.title ?? '',
        type: event?.type ?? ('event' as EventType),
        location: event?.location ?? '',
        starts_at: toDateTimeLocal(event?.starts_at ?? null),
        ends_at: toDateTimeLocal(event?.ends_at ?? null),
        description: event?.description ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && event) {
            post(eventRoutes.update(event.hashid), opts);
        } else {
            post(eventRoutes.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">
                        Title<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="e.g. Quarterly Town Hall"
                        required
                    />
                    <InputError message={errors.title} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label className="mb-1.5 block">Kind</Label>
                        <Select
                            value={data.type}
                            onValueChange={(v) =>
                                setData('type', v as EventType)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {TYPES.map((t) => (
                                    <SelectItem key={t} value={t}>
                                        {TYPE_LABELS[t]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.type} className="mt-1.5" />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Location</Label>
                        <Input
                            value={data.location}
                            onChange={(e) =>
                                setData('location', e.target.value)
                            }
                            placeholder="Room, address or link"
                        />
                        <InputError
                            message={errors.location}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label className="mb-1.5 block">
                            Starts
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            type="datetime-local"
                            value={data.starts_at}
                            onChange={(e) =>
                                setData('starts_at', e.target.value)
                            }
                            required
                        />
                        <InputError
                            message={errors.starts_at}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block">Ends</Label>
                        <Input
                            type="datetime-local"
                            value={data.ends_at}
                            onChange={(e) => setData('ends_at', e.target.value)}
                        />
                        <InputError
                            message={errors.ends_at}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label className="mb-1.5 block">Description</Label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        placeholder="Agenda or details (optional)"
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

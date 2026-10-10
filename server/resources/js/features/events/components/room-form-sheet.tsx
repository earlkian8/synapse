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
import { Switch } from '@/components/ui/switch';
import { eventRoutes } from '../routes';
import type { RoomItem } from '../types';

type Props = {
    room: RoomItem | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** Add or edit a room an event can hold (ADR 0070). */
export function RoomFormSheet({ room, open, onOpenChange }: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>{room ? 'Edit room' : 'New room'}</SheetTitle>
                    <SheetDescription>
                        A space events can book. Two events never hold it at the
                        same time.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <RoomForm
                        key={room?.id ?? 'new'}
                        room={room}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function RoomForm({
    room,
    onDone,
}: {
    room: RoomItem | null;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: room?.name ?? '',
        location: room?.location ?? '',
        capacity: room?.capacity ? String(room.capacity) : '',
        description: room?.description ?? '',
        is_active: room?.is_active ?? true,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(
            room ? eventRoutes.roomUpdate(room.hashid) : eventRoutes.roomStore,
            {
                preserveScroll: true,
                onSuccess: () => onDone(),
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label htmlFor="room-name" className="mb-1.5 block">
                        Name<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        id="room-name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. Boardroom"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div className="grid grid-cols-[1fr_7rem] gap-3">
                    <div>
                        <Label htmlFor="room-location" className="mb-1.5 block">
                            Where
                        </Label>
                        <Input
                            id="room-location"
                            value={data.location}
                            onChange={(e) =>
                                setData('location', e.target.value)
                            }
                            placeholder="Building, floor"
                        />
                        <InputError
                            message={errors.location}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label htmlFor="room-capacity" className="mb-1.5 block">
                            Seats
                        </Label>
                        <Input
                            id="room-capacity"
                            type="number"
                            min={1}
                            value={data.capacity}
                            onChange={(e) =>
                                setData('capacity', e.target.value)
                            }
                        />
                        <InputError
                            message={errors.capacity}
                            className="mt-1.5"
                        />
                    </div>
                </div>

                <div>
                    <Label htmlFor="room-description" className="mb-1.5 block">
                        Notes
                    </Label>
                    <textarea
                        id="room-description"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        placeholder="Screen, whiteboard, video kit… (optional)"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <InputError
                        message={errors.description}
                        className="mt-1.5"
                    />
                </div>

                <label className="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                    <span>
                        <span className="block text-sm font-medium">
                            Taking bookings
                        </span>
                        <span className="block text-xs text-muted-foreground">
                            Off, it takes no new bookings. Events that already
                            hold it keep it.
                        </span>
                    </span>
                    <Switch
                        checked={data.is_active}
                        onCheckedChange={(on) => setData('is_active', on)}
                    />
                </label>
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
                        {room ? 'Save changes' : 'Add room'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

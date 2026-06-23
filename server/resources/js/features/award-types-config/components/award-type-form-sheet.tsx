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
import { AWARD_COLOR_PRESETS } from '@/features/awards/constants';
import type { AwardType } from '@/features/awards/types';
import { cn } from '@/lib/utils';
import { awardTypesConfigRoutes } from '../routes';

type Props = {
    type: AwardType | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function AwardTypeFormSheet({ type, open, onOpenChange }: Props) {
    const isEditing = Boolean(type);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit award type' : 'New award type'}
                    </SheetTitle>
                    <SheetDescription>
                        A kind of recognition the organisation gives out.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={type?.id ?? 'new'}
                        type={type}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    type,
    onDone,
}: {
    type: AwardType | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(type);
    const { data, setData, post, processing, errors } = useForm({
        name: type?.name ?? '',
        description: type?.description ?? '',
        color: type?.color ?? AWARD_COLOR_PRESETS[0],
        is_active: type?.is_active ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && type) {
            post(awardTypesConfigRoutes.update(type.hashid), opts);
        } else {
            post(awardTypesConfigRoutes.store, opts);
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
                        placeholder="e.g. Employee of the Month"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-2 block">Accent colour</Label>
                    <div className="flex flex-wrap gap-2">
                        {AWARD_COLOR_PRESETS.map((preset) => (
                            <button
                                key={preset}
                                type="button"
                                aria-label={preset}
                                onClick={() => setData('color', preset)}
                                className={cn(
                                    'size-7 rounded-full border-2 transition-transform',
                                    data.color === preset
                                        ? 'scale-110 border-foreground'
                                        : 'border-transparent hover:scale-105',
                                )}
                                style={{ backgroundColor: preset }}
                            />
                        ))}
                    </div>
                    <InputError message={errors.color} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">Description</Label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        placeholder="What this recognition is for (optional)"
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    />
                    <InputError
                        message={errors.description}
                        className="mt-1.5"
                    />
                </div>

                <div className="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2.5 dark:border-sidebar-border">
                    <div className="pr-3">
                        <Label className="text-sm">Active</Label>
                        <p className="text-xs text-muted-foreground">
                            Inactive types are hidden from the give-award
                            picker.
                        </p>
                    </div>
                    <Switch
                        checked={data.is_active}
                        onCheckedChange={(v) => setData('is_active', v)}
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

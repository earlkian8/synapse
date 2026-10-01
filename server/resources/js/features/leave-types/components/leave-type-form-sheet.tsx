import { useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
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
import { cn } from '@/lib/utils';
import { leaveTypeRoutes } from '../routes';
import type { LeaveType } from '../types';

const PRESET_COLORS = [
    '#0ABFBF',
    '#6366F1',
    '#F59E0B',
    '#10B981',
    '#EF4444',
    '#EC4899',
    '#8B5CF6',
    '#6B7280',
];

type Props = {
    type: LeaveType | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function LeaveTypeFormSheet({ type, open, onOpenChange }: Props) {
    const isEditing = Boolean(type);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit leave type' : 'New leave type'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update this leave type.'
                            : 'Define a kind of leave the organisation grants.'}
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
    type: LeaveType | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(type);

    const { data, setData, post, processing, errors, transform } = useForm({
        name: type?.name ?? '',
        code: type?.code ?? '',
        color: type?.color ?? PRESET_COLORS[0],
        default_days: type ? String(type.default_days) : '0',
        is_paid: type?.is_paid ?? true,
        allow_half_day: type?.allow_half_day ?? true,
        requires_approval: type?.requires_approval ?? true,
        is_active: type?.is_active ?? true,
        description: type?.description ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            default_days: Number(payload.default_days || 0),
            description: payload.description || null,
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && type) {
            post(leaveTypeRoutes.type(type.hashid), opts);
        } else {
            post(leaveTypeRoutes.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div className="grid gap-4 sm:grid-cols-[1fr_120px]">
                    <Field label="Name" required error={errors.name}>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Vacation Leave"
                            required
                        />
                    </Field>
                    <Field label="Code" required error={errors.code}>
                        <Input
                            value={data.code}
                            onChange={(e) =>
                                setData('code', e.target.value.toUpperCase())
                            }
                            placeholder="VL"
                            className="uppercase"
                            required
                        />
                    </Field>
                </div>

                <Field
                    label="Default days / year"
                    required
                    error={errors.default_days}
                >
                    <Input
                        type="number"
                        min="0"
                        step="0.5"
                        value={data.default_days}
                        onChange={(e) =>
                            setData('default_days', e.target.value)
                        }
                        className="tabular-nums"
                        required
                    />
                </Field>

                <Field label="Colour" error={errors.color}>
                    <div className="flex flex-wrap items-center gap-2">
                        {PRESET_COLORS.map((color) => (
                            <button
                                key={color}
                                type="button"
                                onClick={() => setData('color', color)}
                                className={cn(
                                    'flex size-7 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition',
                                    data.color === color &&
                                        'ring-2 ring-foreground/40',
                                )}
                                style={{ backgroundColor: color }}
                                aria-label={`Use ${color}`}
                            >
                                {data.color === color && (
                                    <Check className="size-4 text-white" />
                                )}
                            </button>
                        ))}
                        <input
                            type="color"
                            value={data.color}
                            onChange={(e) => setData('color', e.target.value)}
                            className="size-7 cursor-pointer rounded-full border border-input bg-transparent p-0"
                            aria-label="Custom colour"
                        />
                    </div>
                </Field>

                <div className="space-y-2">
                    <Toggle
                        label="Paid leave"
                        hint="Counts as paid time off."
                        checked={data.is_paid}
                        onChange={(v) => setData('is_paid', v)}
                    />
                    <Toggle
                        label="Allow half-day"
                        hint="Employees can file a morning or afternoon half-day."
                        checked={data.allow_half_day}
                        onChange={(v) => setData('allow_half_day', v)}
                    />
                    <Toggle
                        label="Requires approval"
                        hint="When off, requests are approved automatically on file."
                        checked={data.requires_approval}
                        onChange={(v) => setData('requires_approval', v)}
                    />
                    <Toggle
                        label="Active"
                        hint="Inactive types can't be selected on new requests."
                        checked={data.is_active}
                        onChange={(v) => setData('is_active', v)}
                    />
                </div>

                <Field label="Description" error={errors.description}>
                    <textarea
                        value={data.description ?? ''}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={2}
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </Field>
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
                        {isEditing ? 'Save changes' : 'Create leave type'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

function Toggle({
    label,
    hint,
    checked,
    onChange,
}: {
    label: string;
    hint: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2.5 dark:border-sidebar-border">
            <div className="pr-3">
                <Label className="text-sm">{label}</Label>
                <p className="text-xs text-muted-foreground">{hint}</p>
            </div>
            <Switch checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

function Field({
    label,
    required = false,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <Label className="mb-1.5 block">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}

import { useForm } from '@inertiajs/react';
import { GripVertical, Plus, Trash2 } from 'lucide-react';
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
import { TYPE_OPTIONS } from '../constants';
import { offboardingRoutes } from '../routes';
import type {
    DepartmentRef,
    OffboardingProgram,
    OffboardingType,
} from '../types';

type Props = {
    program: OffboardingProgram | null;
    departments: DepartmentRef[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const ANY = '__any__';

/** Owner select sentinels: the exiting employee's own department / nobody. */
const OWN = '__own__';
const NONE = '__none__';

type ItemDraft = {
    item: string;
    /** OWN, NONE, or a department id as a string. */
    owner: string;
};

function emptyItem(): ItemDraft {
    return { item: '', owner: NONE };
}

function toDraft(program: OffboardingProgram): ItemDraft[] {
    return (program.items ?? []).map((item) => ({
        item: item.item,
        owner: item.use_employee_department
            ? OWN
            : item.department_id !== null
              ? String(item.department_id)
              : NONE,
    }));
}

export function ProgramFormSheet({
    program,
    departments,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(program);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-2xl"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit template' : 'New template'}
                    </SheetTitle>
                    <SheetDescription>
                        A reusable clearance checklist exits are seeded with.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={program?.id ?? 'new'}
                        program={program}
                        departments={departments}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    program,
    departments,
    onDone,
}: {
    program: OffboardingProgram | null;
    departments: DepartmentRef[];
    onDone: () => void;
}) {
    const isEditing = Boolean(program);

    const { data, setData, post, processing, errors, transform } = useForm({
        name: program?.name ?? '',
        description: program?.description ?? '',
        department_id: program?.department_id
            ? String(program.department_id)
            : ANY,
        exit_type: program?.exit_type ?? ANY,
        is_default: program?.is_default ?? false,
        is_active: program?.is_active ?? true,
        items:
            program && program.items?.length
                ? toDraft(program)
                : [emptyItem()],
    });

    const setItem = (index: number, patch: Partial<ItemDraft>) => {
        setData(
            'items',
            data.items.map((item, i) =>
                i === index ? { ...item, ...patch } : item,
            ),
        );
    };

    const addItem = () => setData('items', [...data.items, emptyItem()]);

    const removeItem = (index: number) =>
        setData(
            'items',
            data.items.filter((_, i) => i !== index),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            name: payload.name,
            description: payload.description || null,
            department_id:
                payload.department_id === ANY
                    ? null
                    : Number(payload.department_id),
            exit_type: payload.exit_type === ANY ? null : payload.exit_type,
            is_default: payload.is_default,
            is_active: payload.is_active,
            items: payload.items.map((item, index) => ({
                item: item.item,
                department_id:
                    item.owner === OWN || item.owner === NONE
                        ? null
                        : Number(item.owner),
                use_employee_department: item.owner === OWN,
                sort_order: index,
            })),
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && program) {
            post(offboardingRoutes.program(program.hashid), opts);
        } else {
            post(offboardingRoutes.programsStore, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-6 px-6 py-6">
                <div className="space-y-4">
                    <div>
                        <Label className="mb-1.5 block">
                            Template name
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Standard Exit Clearance"
                            required
                        />
                        <InputError message={errors.name} className="mt-1.5" />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label className="mb-1.5 block">Department</Label>
                            <Select
                                value={data.department_id}
                                onValueChange={(v) =>
                                    setData('department_id', v)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>
                                        Any department
                                    </SelectItem>
                                    {departments.map((d) => (
                                        <SelectItem
                                            key={d.id}
                                            value={String(d.id)}
                                        >
                                            {d.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Which employees this template targets.
                            </p>
                        </div>
                        <div>
                            <Label className="mb-1.5 block">Exit type</Label>
                            <Select
                                value={data.exit_type}
                                onValueChange={(v) =>
                                    setData(
                                        'exit_type',
                                        v as OffboardingType | typeof ANY,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any type</SelectItem>
                                    {TYPE_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <Toggle
                        label="Default template"
                        hint="Used when no department or exit type specifically matches."
                        checked={data.is_default}
                        onChange={(v) => setData('is_default', v)}
                    />
                    <Toggle
                        label="Active"
                        hint="Inactive templates are never auto-assigned."
                        checked={data.is_active}
                        onChange={(v) => setData('is_active', v)}
                    />
                </div>

                {/* Blueprint sign-off items */}
                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-semibold">
                                Clearance items
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Sign-offs copied to every exit, each owned by a
                                department.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={addItem}
                        >
                            <Plus className="size-4" />
                            Add
                        </Button>
                    </div>

                    <div className="space-y-2">
                        {data.items.map((item, index) => (
                            <div
                                key={index}
                                className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-2.5"
                            >
                                <GripVertical className="mt-2 size-4 shrink-0 text-muted-foreground/50" />
                                <div className="grid flex-1 gap-2 sm:grid-cols-[1fr_190px]">
                                    <div>
                                        <Input
                                            value={item.item}
                                            onChange={(e) =>
                                                setItem(index, {
                                                    item: e.target.value,
                                                })
                                            }
                                            placeholder="Sign-off item"
                                        />
                                        <InputError
                                            message={
                                                (
                                                    errors as Record<
                                                        string,
                                                        string
                                                    >
                                                )[`items.${index}.item`]
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                    <Select
                                        value={item.owner}
                                        onValueChange={(v) =>
                                            setItem(index, { owner: v })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full"
                                            aria-label="Owning department"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={OWN}>
                                                Employee's department
                                            </SelectItem>
                                            <SelectItem value={NONE}>
                                                Unassigned
                                            </SelectItem>
                                            {departments.map((d) => (
                                                <SelectItem
                                                    key={d.id}
                                                    value={String(d.id)}
                                                >
                                                    {d.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => removeItem(index)}
                                    className="mt-1.5 rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                    aria-label="Remove item"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}
                        {data.items.length === 0 && (
                            <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                                No items yet — add a few to build the clearance.
                            </p>
                        )}
                    </div>
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
                        {isEditing ? 'Save changes' : 'Create template'}
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
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border border-border px-3 py-2.5">
            <div>
                <p className="text-sm font-medium">{label}</p>
                <p className="text-xs text-muted-foreground">{hint}</p>
            </div>
            <Switch checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

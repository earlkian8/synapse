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
import {
    CATEGORY_OPTIONS,
    EMPLOYMENT_TYPE_OPTIONS,
} from '../constants';
import { onboardingRoutes } from '../routes';
import type {
    DepartmentRef,
    EmploymentType,
    OnboardingProgram,
    ProgramTaskDraft,
    TaskCategory,
} from '../types';

type Props = {
    program: OnboardingProgram | null;
    departments: DepartmentRef[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const ANY = '__any__';

function emptyTask(): ProgramTaskDraft {
    return {
        title: '',
        description: null,
        category: 'paperwork',
        due_offset_days: 7,
        sort_order: 0,
    };
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
                        {isEditing ? 'Edit program' : 'New program'}
                    </SheetTitle>
                    <SheetDescription>
                        A reusable checklist new hires are onboarded with.
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
    program: OnboardingProgram | null;
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
        employment_type: program?.employment_type ?? ANY,
        is_default: program?.is_default ?? false,
        is_active: program?.is_active ?? true,
        tasks: program?.tasks?.length
            ? program.tasks.map((t) => ({ ...t }))
            : [emptyTask()],
    });

    const setTask = (index: number, patch: Partial<ProgramTaskDraft>) => {
        setData(
            'tasks',
            data.tasks.map((task, i) =>
                i === index ? { ...task, ...patch } : task,
            ),
        );
    };

    const addTask = () => setData('tasks', [...data.tasks, emptyTask()]);

    const removeTask = (index: number) =>
        setData(
            'tasks',
            data.tasks.filter((_, i) => i !== index),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            description: payload.description || null,
            department_id:
                payload.department_id === ANY
                    ? null
                    : Number(payload.department_id),
            employment_type:
                payload.employment_type === ANY
                    ? null
                    : payload.employment_type,
            tasks: payload.tasks.map((task, index) => ({
                title: task.title,
                description: task.description || null,
                category: task.category,
                due_offset_days: Number(task.due_offset_days) || 0,
                sort_order: index,
            })),
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && program) {
            post(onboardingRoutes.program(program.hashid), opts);
        } else {
            post(onboardingRoutes.programsStore, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-6 px-6 py-6">
                <div className="space-y-4">
                    <div>
                        <Label className="mb-1.5 block">
                            Program name
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Standard Onboarding"
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
                        </div>
                        <div>
                            <Label className="mb-1.5 block">
                                Employment type
                            </Label>
                            <Select
                                value={data.employment_type ?? ANY}
                                onValueChange={(v) =>
                                    setData(
                                        'employment_type',
                                        v as EmploymentType,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any type</SelectItem>
                                    {EMPLOYMENT_TYPE_OPTIONS.map((o) => (
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
                        label="Default program"
                        hint="Used when no department or type specifically matches a hire."
                        checked={data.is_default}
                        onChange={(v) => setData('is_default', v)}
                    />
                    <Toggle
                        label="Active"
                        hint="Inactive programs are never auto-assigned."
                        checked={data.is_active}
                        onChange={(v) => setData('is_active', v)}
                    />
                </div>

                {/* Blueprint tasks */}
                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-semibold">Checklist</h3>
                            <p className="text-xs text-muted-foreground">
                                Tasks copied to every hire, due N days after they
                                start.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={addTask}
                        >
                            <Plus className="size-4" />
                            Add
                        </Button>
                    </div>

                    <div className="space-y-2">
                        {data.tasks.map((task, index) => (
                            <div
                                key={index}
                                className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-2.5"
                            >
                                <GripVertical className="mt-2 size-4 shrink-0 text-muted-foreground/50" />
                                <div className="grid flex-1 gap-2 sm:grid-cols-[1fr_140px_110px]">
                                    <div>
                                        <Input
                                            value={task.title}
                                            onChange={(e) =>
                                                setTask(index, {
                                                    title: e.target.value,
                                                })
                                            }
                                            placeholder="Task title"
                                        />
                                        <InputError
                                            message={
                                                (
                                                    errors as Record<
                                                        string,
                                                        string
                                                    >
                                                )[`tasks.${index}.title`]
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                    <Select
                                        value={task.category}
                                        onValueChange={(v) =>
                                            setTask(index, {
                                                category: v as TaskCategory,
                                            })
                                        }
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {CATEGORY_OPTIONS.map((o) => (
                                                <SelectItem
                                                    key={o.value}
                                                    value={o.value}
                                                >
                                                    {o.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <div className="relative">
                                        <Input
                                            type="number"
                                            min="0"
                                            max="365"
                                            value={task.due_offset_days}
                                            onChange={(e) =>
                                                setTask(index, {
                                                    due_offset_days: Number(
                                                        e.target.value,
                                                    ),
                                                })
                                            }
                                            className="pr-9"
                                            aria-label="Due offset in days"
                                        />
                                        <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted-foreground">
                                            d
                                        </span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => removeTask(index)}
                                    className="mt-1.5 rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                    aria-label="Remove task"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}
                        {data.tasks.length === 0 && (
                            <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                                No tasks yet — add a few to build the checklist.
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
                        {isEditing ? 'Save changes' : 'Create program'}
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

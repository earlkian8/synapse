import { useForm } from '@inertiajs/react';
import { ChevronDown, ChevronUp, ListChecks, Plus, Trash2 } from 'lucide-react';
import { useId } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import InputError from '@/components/input-error';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { CATEGORY_OPTIONS, EMPLOYMENT_TYPE_OPTIONS } from '../constants';
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

export function ProgramFormDialog({
    program,
    departments,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(program);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="xl">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <ListChecks />
                        </ModalIcon>
                    }
                    title={isEditing ? 'Edit program' : 'New program'}
                    description="A reusable checklist new hires are onboarded with — who it matches, and what it asks them to do."
                />

                {open && (
                    <FormBody
                        key={program?.id ?? 'new'}
                        program={program}
                        departments={departments}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
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

    /** Swap a task with its neighbour — the list order *is* `sort_order`. */
    const moveTask = (index: number, direction: -1 | 1) => {
        const target = index + direction;

        if (target < 0 || target >= data.tasks.length) {
            return;
        }

        const reordered = [...data.tasks];
        [reordered[index], reordered[target]] = [
            reordered[target],
            reordered[index],
        ];

        setData('tasks', reordered);
    };

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
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-7">
                <Section
                    title="Program"
                    subtitle="What it is called, and which hires it matches."
                >
                    <FormField
                        label="Program name"
                        required
                        error={errors.name}
                    >
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Standard Onboarding"
                            required
                        />
                    </FormField>

                    <FormField
                        label="Description"
                        error={errors.description}
                        hint="Shown on the program card — what this checklist is for."
                    >
                        <Input
                            value={data.description ?? ''}
                            onChange={(e) =>
                                setData('description', e.target.value)
                            }
                            placeholder="e.g. Head-office hires joining a desk role"
                        />
                    </FormField>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Department"
                            error={errors.department_id}
                            hint="Leave on Any to match every department."
                        >
                            <FormSelect
                                value={data.department_id}
                                onChange={(v) => setData('department_id', v)}
                                options={[
                                    { value: ANY, label: 'Any department' },
                                    ...departments.map((d) => ({
                                        value: String(d.id),
                                        label: d.name,
                                    })),
                                ]}
                            />
                        </FormField>
                        <FormField
                            label="Employment type"
                            error={errors.employment_type}
                            hint="Leave on Any to match every type of hire."
                        >
                            <FormSelect
                                value={data.employment_type ?? ANY}
                                onChange={(v) =>
                                    setData(
                                        'employment_type',
                                        v as EmploymentType,
                                    )
                                }
                                options={[
                                    { value: ANY, label: 'Any type' },
                                    ...EMPLOYMENT_TYPE_OPTIONS,
                                ]}
                            />
                        </FormField>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
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
                </Section>

                <Section
                    title="Checklist"
                    subtitle="Copied to every hire this program matches, each due a set number of days after they start."
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={addTask}
                        >
                            <Plus className="size-4" />
                            Add task
                        </Button>
                    }
                >
                    {data.tasks.length === 0 ? (
                        <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                            No tasks yet — add a few to build the checklist.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {data.tasks.map((task, index) => (
                                <TaskRow
                                    key={index}
                                    index={index}
                                    task={task}
                                    total={data.tasks.length}
                                    error={
                                        (errors as Record<string, string>)[
                                            `tasks.${index}.title`
                                        ]
                                    }
                                    onChange={(patch) => setTask(index, patch)}
                                    onMove={(direction) =>
                                        moveTask(index, direction)
                                    }
                                    onRemove={() => removeTask(index)}
                                />
                            ))}
                        </div>
                    )}
                </Section>
            </ModalBody>

            <ModalFooter>
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
            </ModalFooter>
        </form>
    );
}

/**
 * One blueprint task. The row's position *is* its `sort_order`, so the order
 * controls move the row rather than pretending to be a drag handle.
 */
function TaskRow({
    index,
    task,
    total,
    error,
    onChange,
    onMove,
    onRemove,
}: {
    index: number;
    task: ProgramTaskDraft;
    total: number;
    error?: string;
    onChange: (patch: Partial<ProgramTaskDraft>) => void;
    onMove: (direction: -1 | 1) => void;
    onRemove: () => void;
}) {
    const position = `Task ${index + 1}`;

    return (
        <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-2.5">
            <div className="flex shrink-0 flex-col items-center">
                <button
                    type="button"
                    onClick={() => onMove(-1)}
                    disabled={index === 0}
                    className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-30 disabled:hover:text-muted-foreground"
                    aria-label={`Move ${position} up`}
                >
                    <ChevronUp className="size-3.5" />
                </button>
                <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                    {index + 1}
                </span>
                <button
                    type="button"
                    onClick={() => onMove(1)}
                    disabled={index === total - 1}
                    className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-30 disabled:hover:text-muted-foreground"
                    aria-label={`Move ${position} down`}
                >
                    <ChevronDown className="size-3.5" />
                </button>
            </div>

            <div className="grid flex-1 gap-2 sm:grid-cols-[1fr_9rem_7rem]">
                <div>
                    <Input
                        value={task.title}
                        onChange={(e) => onChange({ title: e.target.value })}
                        placeholder="Task title"
                        aria-label={`${position} title`}
                        aria-invalid={error ? true : undefined}
                    />
                    <InputError message={error} role="alert" className="mt-1" />
                </div>

                <FormSelect
                    value={task.category}
                    onChange={(v) => onChange({ category: v as TaskCategory })}
                    options={CATEGORY_OPTIONS}
                />

                <div className="relative">
                    <Input
                        type="number"
                        min="0"
                        max="365"
                        inputMode="numeric"
                        value={task.due_offset_days}
                        onChange={(e) =>
                            onChange({
                                due_offset_days: Number(e.target.value),
                            })
                        }
                        className="pr-12"
                        aria-label={`${position} — days after the hire starts`}
                    />
                    <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted-foreground">
                        days
                    </span>
                </div>
            </div>

            <button
                type="button"
                onClick={onRemove}
                className="mt-1.5 rounded-md p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={`Remove ${position}`}
            >
                <Trash2 className="size-4" />
            </button>
        </div>
    );
}

function Section({
    title,
    subtitle,
    action,
    children,
}: {
    title: string;
    subtitle: string;
    action?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div className="flex items-start justify-between gap-3 border-b border-border/70 pb-2.5">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold tracking-tight">
                        {title}
                    </h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {subtitle}
                    </p>
                </div>
                {action}
            </div>
            {children}
        </section>
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
    const id = useId();

    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border border-border px-3 py-2.5">
            <div className="min-w-0">
                <Label htmlFor={id} className="cursor-pointer">
                    {label}
                </Label>
                <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
            </div>
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onChange}
                className="shrink-0"
            />
        </div>
    );
}

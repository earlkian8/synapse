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
import { CATEGORY_OPTIONS } from '../constants';
import { onboardingRoutes } from '../routes';
import type {
    AssigneeRef,
    OnboardingTask,
    TaskCategory,
} from '../types';

type Props = {
    task: OnboardingTask | null;
    caseHashid: string;
    assignees: AssigneeRef[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

export function TaskFormSheet({
    task,
    caseHashid,
    assignees,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(task);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>{isEditing ? 'Edit task' : 'Add task'}</SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update this checklist item.'
                            : 'Add a checklist item to this onboarding.'}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={task?.id ?? 'new'}
                        task={task}
                        caseHashid={caseHashid}
                        assignees={assignees}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    task,
    caseHashid,
    assignees,
    onDone,
}: {
    task: OnboardingTask | null;
    caseHashid: string;
    assignees: AssigneeRef[];
    onDone: () => void;
}) {
    const isEditing = Boolean(task);

    const { data, setData, post, processing, errors, transform } = useForm({
        title: task?.title ?? '',
        description: task?.description ?? '',
        category: (task?.category ?? 'paperwork') as TaskCategory,
        assigned_to: task?.assigned_to ? String(task.assigned_to) : NONE,
        due_date: task?.due_date ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            description: payload.description || null,
            assigned_to:
                payload.assigned_to === NONE
                    ? null
                    : Number(payload.assigned_to),
            due_date: payload.due_date || null,
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && task) {
            post(onboardingRoutes.task(task.id), opts);
        } else {
            post(onboardingRoutes.tasks(caseHashid), opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <Field label="Title" required error={errors.title}>
                    <Input
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="e.g. Sign employment contract"
                        required
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Category" required error={errors.category}>
                        <Select
                            value={data.category}
                            onValueChange={(v) =>
                                setData('category', v as TaskCategory)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {CATEGORY_OPTIONS.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label="Due date" error={errors.due_date}>
                        <Input
                            type="date"
                            value={data.due_date ?? ''}
                            onChange={(e) => setData('due_date', e.target.value)}
                        />
                    </Field>
                </div>

                <Field label="Assignee" error={errors.assigned_to}>
                    <Select
                        value={data.assigned_to}
                        onValueChange={(v) => setData('assigned_to', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>Unassigned</SelectItem>
                            {assignees.map((a) => (
                                <SelectItem key={a.id} value={String(a.id)}>
                                    {a.full_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label="Notes" error={errors.description}>
                    <textarea
                        value={data.description ?? ''}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
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
                        {isEditing ? 'Save changes' : 'Add task'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
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

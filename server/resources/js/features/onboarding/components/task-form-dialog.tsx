import { useForm } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
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
import { Spinner } from '@/components/ui/spinner';
import { CATEGORY_OPTIONS } from '../constants';
import { onboardingRoutes } from '../routes';
import type { AssigneeRef, OnboardingTask, TaskCategory } from '../types';

type Props = {
    task: OnboardingTask | null;
    caseHashid: string;
    assignees: AssigneeRef[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

export function TaskFormDialog({
    task,
    caseHashid,
    assignees,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(task);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <ClipboardCheck />
                        </ModalIcon>
                    }
                    title={isEditing ? 'Edit task' : 'Add task'}
                    description={
                        isEditing
                            ? 'Update this checklist item.'
                            : 'Add a checklist item to this onboarding.'
                    }
                />

                {open && (
                    <FormBody
                        key={task?.id ?? 'new'}
                        task={task}
                        caseHashid={caseHashid}
                        assignees={assignees}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
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
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField label="Title" required error={errors.title}>
                    <Input
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="e.g. Sign employment contract"
                        required
                    />
                </FormField>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Category"
                        required
                        error={errors.category}
                        hint="Groups the task on the checklist."
                    >
                        <FormSelect
                            value={data.category}
                            onChange={(v) =>
                                setData('category', v as TaskCategory)
                            }
                            options={CATEGORY_OPTIONS}
                        />
                    </FormField>
                    <FormField
                        label="Due date"
                        error={errors.due_date}
                        hint="Leave empty for a task with no deadline."
                    >
                        <Input
                            type="date"
                            value={data.due_date ?? ''}
                            onChange={(e) =>
                                setData('due_date', e.target.value)
                            }
                        />
                    </FormField>
                </div>

                <FormField
                    label="Assignee"
                    error={errors.assigned_to}
                    hint="Who is responsible for getting this done."
                >
                    <FormSelect
                        value={data.assigned_to}
                        onChange={(v) => setData('assigned_to', v)}
                        options={[
                            { value: NONE, label: 'Unassigned' },
                            ...assignees.map((a) => ({
                                value: String(a.id),
                                label: a.full_name,
                            })),
                        ]}
                    />
                </FormField>

                <FormField label="Notes" error={errors.description}>
                    <textarea
                        value={data.description ?? ''}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={4}
                        placeholder="Anything the assignee needs to know to finish this."
                        className="flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 aria-invalid:border-destructive aria-invalid:ring-destructive/20"
                    />
                </FormField>
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
                    {isEditing ? 'Save changes' : 'Add task'}
                </Button>
            </ModalFooter>
        </form>
    );
}

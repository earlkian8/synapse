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
import { UNASSIGNED_DEPARTMENT } from '../constants';
import { offboardingRoutes } from '../routes';
import type { ClearanceItem, DepartmentRef } from '../types';

type Props = {
    item: ClearanceItem | null;
    caseHashid: string;
    departments: DepartmentRef[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

export function ClearanceItemFormDialog({
    item,
    caseHashid,
    departments,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(item);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <ClipboardCheck />
                        </ModalIcon>
                    }
                    title={
                        isEditing ? 'Edit clearance item' : 'Add clearance item'
                    }
                    description={
                        isEditing
                            ? 'Update this clearance sign-off.'
                            : 'Add a sign-off to this exit clearance.'
                    }
                />

                {open && (
                    <FormBody
                        key={item?.id ?? 'new'}
                        item={item}
                        caseHashid={caseHashid}
                        departments={departments}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    item,
    caseHashid,
    departments,
    onDone,
}: {
    item: ClearanceItem | null;
    caseHashid: string;
    departments: DepartmentRef[];
    onDone: () => void;
}) {
    const isEditing = Boolean(item);

    const { data, setData, post, processing, errors, transform } = useForm({
        item: item?.item ?? '',
        department_id: item?.department_id ? String(item.department_id) : NONE,
        remarks: item?.remarks ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            department_id:
                payload.department_id === NONE
                    ? null
                    : Number(payload.department_id),
            remarks: payload.remarks || null,
        }));

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && item) {
            post(offboardingRoutes.item(item.id), opts);
        } else {
            post(offboardingRoutes.clearance(caseHashid), opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField label="Item" required error={errors.item}>
                    <Input
                        value={data.item}
                        onChange={(e) => setData('item', e.target.value)}
                        placeholder="e.g. Return laptop & peripherals"
                        required
                    />
                </FormField>

                <FormField
                    label="Responsible department"
                    error={errors.department_id}
                    hint="The department that signs this item off."
                >
                    <FormSelect
                        value={data.department_id}
                        onChange={(v) => setData('department_id', v)}
                        options={[
                            { value: NONE, label: UNASSIGNED_DEPARTMENT },
                            ...departments.map((d) => ({
                                value: String(d.id),
                                label: d.name,
                            })),
                        ]}
                    />
                </FormField>

                <FormField label="Remarks" error={errors.remarks}>
                    <textarea
                        value={data.remarks ?? ''}
                        onChange={(e) => setData('remarks', e.target.value)}
                        rows={3}
                        placeholder="Sign-off note, or why this item is flagged…"
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
                    {isEditing ? 'Save changes' : 'Add item'}
                </Button>
            </ModalFooter>
        </form>
    );
}

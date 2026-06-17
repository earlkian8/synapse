import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { enrollEmployee, updateEnrollment } from '../api';
import { STATUS_LABELS } from '../constants';
import type {
    BenefitEnrollment,
    EnrollableEmployee,
    EnrollmentStatus,
} from '../types';

const STATUSES: EnrollmentStatus[] = [
    'active',
    'pending',
    'waived',
    'terminated',
];

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    planHashid: string;
    enrollable: EnrollableEmployee[];
    enrollment: BenefitEnrollment | null;
};

/**
 * Enroll an employee in a plan, or edit an existing enrollment. The employee
 * picker only shows when enrolling — on an existing enrollment the employee is
 * fixed and only the status / reference / dates / notes change.
 */
export function EnrollDialog({
    open,
    onOpenChange,
    planHashid,
    enrollable,
    enrollment,
}: Props) {
    const isEditing = Boolean(enrollment);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {isEditing ? 'Edit enrollment' : 'Enroll employee'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEditing
                            ? `${enrollment?.employee?.full_name ?? 'This employee'}'s coverage under this plan.`
                            : 'Add an employee to this benefit plan.'}
                    </DialogDescription>
                </DialogHeader>

                {open && (
                    <FormBody
                        key={enrollment?.id ?? 'new'}
                        planHashid={planHashid}
                        enrollable={enrollable}
                        enrollment={enrollment}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function FormBody({
    planHashid,
    enrollable,
    enrollment,
    onDone,
}: {
    planHashid: string;
    enrollable: EnrollableEmployee[];
    enrollment: BenefitEnrollment | null;
    onDone: () => void;
}) {
    const isEditing = Boolean(enrollment);
    const today = new Date().toISOString().slice(0, 10);

    const [employeeId, setEmployeeId] = useState('');
    const [status, setStatus] = useState<EnrollmentStatus>(
        enrollment?.status ?? 'active',
    );
    const [reference, setReference] = useState(enrollment?.reference_no ?? '');
    const [enrolledOn, setEnrolledOn] = useState(
        enrollment?.enrolled_on ?? today,
    );
    const [endedOn, setEndedOn] = useState(enrollment?.ended_on ?? '');
    const [notes, setNotes] = useState(enrollment?.notes ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const canSubmit = isEditing || employeeId !== '';

    const handlers = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onError: setErrors,
        onSuccess: onDone,
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (isEditing && enrollment) {
            updateEnrollment(
                enrollment.id,
                {
                    status,
                    reference_no: reference || null,
                    enrolled_on: enrolledOn || null,
                    ended_on: endedOn || null,
                    notes: notes || null,
                },
                handlers,
            );
        } else {
            enrollEmployee(
                planHashid,
                {
                    employee_id: Number(employeeId),
                    status,
                    reference_no: reference || null,
                    enrolled_on: enrolledOn || null,
                    notes: notes || null,
                },
                handlers,
            );
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4 py-1">
            {!isEditing && (
                <Field label="Employee" error={errors.employee_id} required>
                    {enrollable.length === 0 ? (
                        <p className="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                            Every active employee is already enrolled.
                        </p>
                    ) : (
                        <Select
                            value={employeeId}
                            onValueChange={setEmployeeId}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Select an employee" />
                            </SelectTrigger>
                            <SelectContent>
                                {enrollable.map((e) => (
                                    <SelectItem key={e.id} value={String(e.id)}>
                                        {e.full_name}
                                        <span className="ml-1 text-muted-foreground">
                                            · {e.employee_no}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </Field>
            )}

            <div className="grid grid-cols-2 gap-3">
                <Field label="Status" error={errors.status}>
                    <Select
                        value={status}
                        onValueChange={(v) => setStatus(v as EnrollmentStatus)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {STATUSES.map((s) => (
                                <SelectItem key={s} value={s}>
                                    {STATUS_LABELS[s]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    label="Reference / member no."
                    error={errors.reference_no}
                >
                    <Input
                        value={reference}
                        onChange={(e) => setReference(e.target.value)}
                        placeholder="Optional"
                    />
                </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <Field label="Enrolled on" error={errors.enrolled_on}>
                    <Input
                        type="date"
                        value={enrolledOn}
                        onChange={(e) => setEnrolledOn(e.target.value)}
                    />
                </Field>
                {isEditing && (
                    <Field label="Ended on" error={errors.ended_on}>
                        <Input
                            type="date"
                            value={endedOn}
                            onChange={(e) => setEndedOn(e.target.value)}
                        />
                    </Field>
                )}
            </div>

            <Field label="Notes" error={errors.notes}>
                <Input
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="Optional"
                />
            </Field>

            <DialogFooter className="mt-1">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={processing || !canSubmit}>
                    {processing && <Spinner />}
                    {isEditing ? 'Save changes' : 'Enroll'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function Field({
    label,
    error,
    required = false,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label className="text-xs">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            {error && <p className="text-xs text-rose-600">{error}</p>}
        </div>
    );
}

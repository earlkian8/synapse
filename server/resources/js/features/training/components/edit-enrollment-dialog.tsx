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
import { updateEnrollment } from '../api';
import { ENROLLMENT_STATUS_LABELS } from '../constants';
import type { TrainingEnrollment, TrainingEnrollmentStatus } from '../types';

const STATUSES: TrainingEnrollmentStatus[] = [
    'enrolled',
    'completed',
    'dropped',
];

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    enrollment: TrainingEnrollment | null;
};

/**
 * Edit one enrollment — its status, completion score and remarks. The employee
 * is fixed; enrolling people is handled by the bulk-enroll dialog.
 */
export function EditEnrollmentDialog({
    open,
    onOpenChange,
    enrollment,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit enrollment</DialogTitle>
                    <DialogDescription>
                        {enrollment?.employee?.full_name ?? 'This employee'}'s
                        status in this program.
                    </DialogDescription>
                </DialogHeader>

                {open && enrollment && (
                    <FormBody
                        key={enrollment.id}
                        enrollment={enrollment}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function FormBody({
    enrollment,
    onDone,
}: {
    enrollment: TrainingEnrollment;
    onDone: () => void;
}) {
    const [status, setStatus] = useState<TrainingEnrollmentStatus>(
        enrollment.status,
    );
    const [score, setScore] = useState(
        enrollment.score != null ? String(enrollment.score) : '',
    );
    const [remarks, setRemarks] = useState(enrollment.remarks ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        updateEnrollment(
            enrollment.id,
            {
                status,
                score: score === '' ? null : Number(score),
                remarks: remarks || null,
            },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: setErrors,
                onSuccess: onDone,
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4 py-1">
            <div className="grid grid-cols-2 gap-3">
                <Field label="Status" error={errors.status}>
                    <Select
                        value={status}
                        onValueChange={(v) =>
                            setStatus(v as TrainingEnrollmentStatus)
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {STATUSES.map((s) => (
                                <SelectItem key={s} value={s}>
                                    {ENROLLMENT_STATUS_LABELS[s]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field label="Score (%)" error={errors.score}>
                    <Input
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        inputMode="decimal"
                        value={score}
                        onChange={(e) => setScore(e.target.value)}
                        placeholder="Optional"
                    />
                </Field>
            </div>

            <Field label="Remarks" error={errors.remarks}>
                <Input
                    value={remarks}
                    onChange={(e) => setRemarks(e.target.value)}
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
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    Save changes
                </Button>
            </DialogFooter>
        </form>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label className="text-xs">{label}</Label>
            {children}
            {error && <p className="text-xs text-rose-600">{error}</p>}
        </div>
    );
}

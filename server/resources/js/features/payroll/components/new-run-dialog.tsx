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
import { Spinner } from '@/components/ui/spinner';
import { createRun } from '../api';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type Form = {
    name: string;
    start_date: string;
    end_date: string;
    pay_date: string;
};

const EMPTY: Form = { name: '', start_date: '', end_date: '', pay_date: '' };

/**
 * Create a payroll run. On submit the server generates every employee's payslip
 * from attendance + salaries, then redirects to the run.
 */
export function NewRunDialog({ open, onOpenChange }: Props) {
    const [form, setForm] = useState<Form>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const set = (key: keyof Form, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const submit = () => {
        createRun(form, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: setErrors,
            onSuccess: () => {
                setForm(EMPTY);
                setErrors({});
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New payroll run</DialogTitle>
                    <DialogDescription>
                        Payslips are generated for every active employee from
                        their salary and the attendance in this period.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4 py-2">
                    <Field label="Run name" error={errors.name}>
                        <Input
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            placeholder="e.g. Jun 1–15, 2026"
                        />
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Period start" error={errors.start_date}>
                            <Input
                                type="date"
                                value={form.start_date}
                                onChange={(e) =>
                                    set('start_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Period end" error={errors.end_date}>
                            <Input
                                type="date"
                                value={form.end_date}
                                onChange={(e) =>
                                    set('end_date', e.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <Field label="Pay date" error={errors.pay_date}>
                        <Input
                            type="date"
                            value={form.pay_date}
                            onChange={(e) => set('pay_date', e.target.value)}
                        />
                    </Field>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={processing}>
                        {processing && <Spinner />}
                        Create & process
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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

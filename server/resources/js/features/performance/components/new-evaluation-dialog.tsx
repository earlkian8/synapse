import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { createEvaluation } from '../api';
import { formatDate } from '../constants';
import type { EvaluationPeriodOption, PerformanceEmployee } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    periods: EvaluationPeriodOption[];
    employees: PerformanceEmployee[];
    /** `${periodId}:${employeeId}` pairs already evaluated. */
    taken: Set<string>;
};

/**
 * Open a new evaluation: pick an open review period, then an employee who is not
 * already being evaluated in it. Score lines are seeded server-side from the
 * active KPI criteria, so the form only collects who + when.
 */
export function NewEvaluationDialog({
    open,
    onOpenChange,
    periods,
    employees,
    taken,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New evaluation</DialogTitle>
                    <DialogDescription>
                        Open a KPI scorecard for an employee in an active review
                        period.
                    </DialogDescription>
                </DialogHeader>

                {open && (
                    <FormBody
                        periods={periods}
                        employees={employees}
                        taken={taken}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function FormBody({
    periods,
    employees,
    taken,
    onDone,
}: {
    periods: EvaluationPeriodOption[];
    employees: PerformanceEmployee[];
    taken: Set<string>;
    onDone: () => void;
}) {
    const openPeriods = useMemo(
        () => periods.filter((p) => p.status === 'open' && !p.is_archived),
        [periods],
    );

    const [periodId, setPeriodId] = useState('');
    const [employeeId, setEmployeeId] = useState('');
    const [processing, setProcessing] = useState(false);

    // Employees not yet evaluated in the chosen period.
    const available = useMemo(() => {
        if (periodId === '') {
            return employees;
        }

        return employees.filter((e) => !taken.has(`${periodId}:${e.id}`));
    }, [employees, periodId, taken]);

    const canSubmit = periodId !== '' && employeeId !== '';

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!canSubmit) {
            return;
        }

        createEvaluation(
            {
                employee_id: Number(employeeId),
                evaluation_period_id: Number(periodId),
            },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
            },
        );
    };

    if (openPeriods.length === 0) {
        return (
            <div className="flex flex-col gap-4 py-1">
                <p className="rounded-md border border-dashed border-border px-3 py-3 text-sm text-muted-foreground">
                    There is no open review period. Open one under{' '}
                    <Link
                        href="/setup/kpi"
                        className="font-medium text-foreground underline-offset-2 hover:underline"
                    >
                        Company Setup → KPI &amp; Evaluation Criteria
                    </Link>
                    .
                </p>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        Close
                    </Button>
                </DialogFooter>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4 py-1">
            <div className="flex flex-col gap-1.5">
                <Label className="text-xs">
                    Review period
                    <span className="ml-0.5 text-destructive">*</span>
                </Label>
                <Select
                    value={periodId}
                    onValueChange={(v) => {
                        setPeriodId(v);
                        setEmployeeId('');
                    }}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Select a period" />
                    </SelectTrigger>
                    <SelectContent>
                        {openPeriods.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                                {p.name}
                                <span className="ml-1 text-muted-foreground">
                                    · {formatDate(p.start_date)} –{' '}
                                    {formatDate(p.end_date)}
                                </span>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label className="text-xs">
                    Employee<span className="ml-0.5 text-destructive">*</span>
                </Label>
                {periodId !== '' && available.length === 0 ? (
                    <p className="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                        Every active employee already has an evaluation for this
                        period.
                    </p>
                ) : (
                    <Select
                        value={employeeId}
                        onValueChange={setEmployeeId}
                        disabled={periodId === ''}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue
                                placeholder={
                                    periodId === ''
                                        ? 'Pick a period first'
                                        : 'Select an employee'
                                }
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {available.map((e) => (
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
            </div>

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
                    Open scorecard
                </Button>
            </DialogFooter>
        </form>
    );
}

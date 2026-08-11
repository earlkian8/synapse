import { ClipboardCheck, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { cn } from '@/lib/utils';
import { createEvaluation } from '../api';
import type {
    EvaluationPeriodOption,
    PerformanceEmployee,
    ReviewTemplateOption,
} from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    periods: EvaluationPeriodOption[];
    templates: ReviewTemplateOption[];
    employees: PerformanceEmployee[];
    /** `${periodId}:${employeeId}` pairs that already have an appraisal. */
    taken: Set<string>;
    defaultPeriodId: number | null;
};

/**
 * Open one appraisal. Two decisions and a person: which cycle, which framework,
 * and who — with the framework left on "match the employee" by default, so the
 * common case is a single click after picking the name.
 */
export function OpenAppraisalModal({
    open,
    onOpenChange,
    periods,
    templates,
    employees,
    taken,
    defaultPeriodId,
}: Props) {
    const openPeriods = periods.filter(
        (period) => period.status === 'open' && !period.is_archived,
    );

    const [periodId, setPeriodId] = useState(() =>
        String(
            openPeriods.find((period) => period.id === defaultPeriodId)?.id ??
                openPeriods[0]?.id ??
                '',
        ),
    );
    const [templateId, setTemplateId] = useState('auto');
    const [employeeId, setEmployeeId] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);

    const candidates = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return employees
            .filter((employee) => !taken.has(`${periodId}:${employee.id}`))
            .filter(
                (employee) =>
                    needle === '' ||
                    employee.full_name.toLowerCase().includes(needle) ||
                    employee.employee_no.toLowerCase().includes(needle),
            )
            .slice(0, 60);
    }, [employees, taken, periodId, search]);

    const submit = () => {
        if (!periodId || employeeId === null) {
            return;
        }

        createEvaluation(
            {
                employee_id: employeeId,
                evaluation_period_id: Number(periodId),
                review_template_id:
                    templateId === 'auto' ? null : Number(templateId),
            },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <ClipboardCheck />
                        </ModalIcon>
                    }
                    title="Open an appraisal"
                    description="Pick the cycle and the person. The framework decides what gets measured and how the result is reported."
                />

                <ModalBody className="space-y-5">
                    {openPeriods.length === 0 ? (
                        <p className="rounded-lg border border-dashed border-border bg-muted/30 px-4 py-6 text-center text-sm text-muted-foreground">
                            No review cycle is open. Open one under Company
                            Setup → Performance framework first.
                        </p>
                    ) : (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField label="Review cycle" required>
                                    <FormSelect
                                        value={periodId}
                                        onChange={setPeriodId}
                                        options={openPeriods.map((period) => ({
                                            value: String(period.id),
                                            label: period.name,
                                        }))}
                                    />
                                </FormField>

                                <FormField
                                    label="Framework"
                                    hint="Leave on automatic to use the framework that covers this employee."
                                >
                                    <FormSelect
                                        value={templateId}
                                        onChange={setTemplateId}
                                        options={[
                                            {
                                                value: 'auto',
                                                label: 'Match the employee',
                                            },
                                            ...templates.map((template) => ({
                                                value: String(template.id),
                                                label: `${template.name} (${template.items_count})`,
                                            })),
                                        ]}
                                    />
                                </FormField>
                            </div>

                            <FormField label="Employee" required group>
                                <div className="relative mb-2">
                                    <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        placeholder="Search by name or employee no."
                                        className="pl-8"
                                    />
                                </div>

                                {candidates.length === 0 ? (
                                    <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                        {search
                                            ? 'Nobody matches that search.'
                                            : 'Everyone active already has an appraisal for this cycle.'}
                                    </p>
                                ) : (
                                    <ul className="max-h-64 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                                        {candidates.map((employee) => (
                                            <li key={employee.id}>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setEmployeeId(
                                                            employee.id,
                                                        )
                                                    }
                                                    aria-pressed={
                                                        employeeId ===
                                                        employee.id
                                                    }
                                                    className={cn(
                                                        'flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left text-sm transition-colors',
                                                        employeeId ===
                                                            employee.id
                                                            ? 'bg-[#0ABFBF]/10 font-medium text-foreground'
                                                            : 'hover:bg-muted/60',
                                                    )}
                                                >
                                                    <span className="min-w-0 truncate">
                                                        {employee.full_name}
                                                    </span>
                                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                        {employee.employee_no}
                                                    </span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </FormField>
                        </>
                    )}
                </ModalBody>

                <ModalFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={
                            processing || !periodId || employeeId === null
                        }
                    >
                        {processing && <Spinner />}
                        Open appraisal
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}

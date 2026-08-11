import { Rocket } from 'lucide-react';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { launchCycle } from '../api';
import type {
    EvaluationPeriodOption,
    PerformanceDepartment,
    PerformanceEmployee,
    ReviewTemplateOption,
} from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    periods: EvaluationPeriodOption[];
    templates: ReviewTemplateOption[];
    departments: PerformanceDepartment[];
    employees: PerformanceEmployee[];
    /** `${periodId}:${employeeId}` pairs that already have an appraisal. */
    taken: Set<string>;
    defaultPeriodId: number | null;
};

/**
 * Launch a review cycle — open every appraisal in one action.
 *
 * The number that matters is shown before the button is pressed: how many
 * appraisals this will actually create, and how many of the chosen population
 * already have one. Re-running the launch is safe (the server skips anyone
 * already appraised), and saying so removes the reason to hesitate.
 */
export function LaunchCycleModal({
    open,
    onOpenChange,
    periods,
    templates,
    departments,
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
    const [scope, setScope] = useState<'all' | 'departments'>('all');
    const [departmentIds, setDepartmentIds] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);

    const preview = useMemo(() => {
        const inScope = employees.filter(
            (employee) =>
                scope === 'all' ||
                (employee.department_id !== null &&
                    departmentIds.includes(employee.department_id)),
        );

        const existing = inScope.filter((employee) =>
            taken.has(`${periodId}:${employee.id}`),
        ).length;

        return {
            total: inScope.length,
            existing,
            opening: inScope.length - existing,
        };
    }, [employees, scope, departmentIds, taken, periodId]);

    const toggleDepartment = (id: number) =>
        setDepartmentIds((prev) =>
            prev.includes(id)
                ? prev.filter((value) => value !== id)
                : [...prev, id],
        );

    const submit = () => {
        if (!periodId) {
            return;
        }

        launchCycle(
            {
                evaluation_period_id: Number(periodId),
                review_template_id:
                    templateId === 'auto' ? null : Number(templateId),
                scope,
                department_ids: scope === 'departments' ? departmentIds : [],
            },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    const blocked =
        !periodId || (scope === 'departments' && departmentIds.length === 0);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Rocket />
                        </ModalIcon>
                    }
                    title="Launch the review cycle"
                    description="Open appraisals for everyone in scope at once. Anyone who already has one is skipped, so this is safe to re-run as people join."
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
                                    hint="Automatic gives each person the framework that covers them."
                                >
                                    <FormSelect
                                        value={templateId}
                                        onChange={setTemplateId}
                                        options={[
                                            {
                                                value: 'auto',
                                                label: 'Match each employee',
                                            },
                                            ...templates.map((template) => ({
                                                value: String(template.id),
                                                label: `${template.name} for everyone`,
                                            })),
                                        ]}
                                    />
                                </FormField>
                            </div>

                            <FormField label="Who is in this launch" group>
                                <div className="flex gap-2">
                                    {(
                                        [
                                            ['all', 'Everyone active'],
                                            [
                                                'departments',
                                                'Chosen departments',
                                            ],
                                        ] as const
                                    ).map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() => setScope(value)}
                                            aria-pressed={scope === value}
                                            className={cn(
                                                'min-h-9 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                                scope === value
                                                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 text-foreground'
                                                    : 'border-border text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>

                                {scope === 'departments' && (
                                    <ul className="mt-3 max-h-56 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                                        {departments.map((department) => (
                                            <li
                                                key={department.id}
                                                className="flex items-center gap-3 px-3 py-2.5"
                                            >
                                                <Checkbox
                                                    id={`dept-${department.id}`}
                                                    checked={departmentIds.includes(
                                                        department.id,
                                                    )}
                                                    onCheckedChange={() =>
                                                        toggleDepartment(
                                                            department.id,
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`dept-${department.id}`}
                                                    className="min-w-0 flex-1 cursor-pointer truncate text-sm font-normal"
                                                >
                                                    {department.name}
                                                </Label>
                                                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                    {department.headcount}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </FormField>

                            <div className="rounded-lg border border-border bg-muted/30 px-4 py-3">
                                <p className="text-sm">
                                    <span className="text-lg font-semibold tabular-nums">
                                        {preview.opening}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        {preview.opening === 1
                                            ? 'appraisal will be opened'
                                            : 'appraisals will be opened'}
                                    </span>
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {preview.total} in scope
                                    {preview.existing > 0 &&
                                        ` · ${preview.existing} already have one`}
                                </p>
                            </div>
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
                            processing || blocked || preview.opening === 0
                        }
                    >
                        {processing && <Spinner />}
                        Launch cycle
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}

import { Flag, Search } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { createGoal, createOwnGoal, updateGoal } from '../api';
import type { GoalPayload } from '../api';
import { TEXTAREA } from '../constants';
import type {
    GoalEmployeeOption,
    GoalMeasure,
    GoalTemplateOption,
    PerformanceGoal,
} from '../types';

type Mode =
    /** HR sets a goal for one or more people. */
    | {
          kind: 'assign';
          employees: GoalEmployeeOption[];
          departments: { id: number; name: string }[];
      }
    /** Someone adds a goal of their own. */
    | { kind: 'own' }
    /** HR edits a goal. */
    | { kind: 'edit'; goal: PerformanceGoal };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode: Mode;
    /** The cycles a new goal can go in (not closed). */
    periods: { id: number; name: string }[];
    defaultPeriodId: number | null;
    templates: GoalTemplateOption[];
};

type Form = {
    templateId: string;
    title: string;
    description: string;
    measure: GoalMeasure;
    start: string;
    target: string;
    unit: string;
    weight: string;
    due: string;
};

function initial(mode: Mode): Form {
    if (mode.kind === 'edit') {
        const g = mode.goal;

        return {
            templateId: 'none',
            title: g.title,
            description: g.description ?? '',
            measure: g.measure,
            start: String(g.start_value),
            target: String(g.target_value),
            unit: g.unit ?? '',
            weight: String(g.weight),
            due: g.due_on ?? '',
        };
    }

    return {
        templateId: 'none',
        title: '',
        description: '',
        measure: 'percent',
        start: '0',
        target: '',
        unit: '',
        weight: '1',
        due: '',
    };
}

/**
 * Set a goal (ADR 0073) — for one person or several at once, written out or
 * started from the library — or edit one. A goal is measured either as progress
 * to 100 % or as a number moving from a start to a target ("40 deals", "defects
 * from 40 down to 10"). Starting from the library copies its wording and target.
 */
export function GoalFormModal({
    open,
    onOpenChange,
    mode,
    periods,
    defaultPeriodId,
    templates,
}: Props) {
    const [form, setForm] = useState<Form>(() => initial(mode));
    const [periodId, setPeriodId] = useState(
        String(
            periods.find((p) => p.id === defaultPeriodId)?.id ??
                periods[0]?.id ??
                '',
        ),
    );
    const [people, setPeople] = useState<number[]>([]);
    const [query, setQuery] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const set = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const pickTemplate = (value: string) => {
        const template = templates.find((t) => String(t.id) === value);

        setForm((prev) =>
            template
                ? {
                      ...prev,
                      templateId: value,
                      title: template.name,
                      description: template.description ?? '',
                      measure: template.measure,
                      start: String(template.start_value),
                      target: String(template.target_value),
                      unit: template.unit ?? '',
                  }
                : { ...prev, templateId: 'none' },
        );
    };

    const matches = useMemo(() => {
        if (mode.kind !== 'assign') {
            return [];
        }

        const words = query.toLowerCase().split(/\s+/).filter(Boolean);

        return mode.employees.filter((e) => {
            const haystack = [e.full_name, e.employee_no, e.department]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return words.every((word) => haystack.includes(word));
        });
    }, [mode, query]);

    const close = (next: boolean) => {
        if (!next) {
            setForm(initial(mode));
            setPeople([]);
            setQuery('');
            setErrors({});
        }

        onOpenChange(next);
    };

    const submit = () => {
        const number = form.measure === 'number';
        const payload: GoalPayload = {
            title: form.title.trim(),
            description: form.description.trim() || null,
            measure: form.measure,
            start_value: number ? Number(form.start || 0) : null,
            target_value:
                number && form.target !== '' ? Number(form.target) : null,
            unit: number ? form.unit.trim() || null : null,
            weight: form.weight === '' ? null : Number(form.weight),
            due_on: form.due || null,
        };

        const handlers = {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => close(false),
            onError: (e: Record<string, string>) => setErrors(e),
        };

        if (mode.kind === 'edit') {
            updateGoal(mode.goal.hashid, payload, handlers);

            return;
        }

        const create = {
            ...payload,
            evaluation_period_id: Number(periodId),
            goal_template_id:
                form.templateId === 'none' ? null : Number(form.templateId),
        };

        if (mode.kind === 'assign') {
            createGoal({ ...create, employee_ids: people }, handlers);
        } else {
            createOwnGoal(create, handlers);
        }
    };

    const blocked =
        form.title.trim() === '' ||
        (mode.kind !== 'edit' && !periodId) ||
        (mode.kind === 'assign' && people.length === 0) ||
        (form.measure === 'number' && form.target === '');

    const title =
        mode.kind === 'edit'
            ? 'Edit goal'
            : mode.kind === 'own'
              ? 'Add a goal'
              : 'Set a goal';

    return (
        <Modal open={open} onOpenChange={close}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Flag />
                        </ModalIcon>
                    }
                    title={title}
                    description={
                        mode.kind === 'assign'
                            ? 'Each person gets their own copy of the goal to check in on. They’re told it was set.'
                            : mode.kind === 'own'
                              ? 'A goal of your own for the cycle. Your evaluator sees it beside your appraisal.'
                              : 'Change the wording, target, weight or due date. Check-ins already made stay as they were.'
                    }
                />

                <ModalBody className="space-y-4">
                    {mode.kind === 'assign' && (
                        <FormField
                            label="For"
                            required
                            group
                            error={errors.employee_ids}
                        >
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <div className="relative flex-1">
                                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        value={query}
                                        onChange={(e) =>
                                            setQuery(e.target.value)
                                        }
                                        placeholder="Search by name, number or team"
                                        aria-label="Search employees"
                                        className="pl-9"
                                    />
                                </div>
                                <FormSelect
                                    value="none"
                                    onChange={(value) => {
                                        const ids = mode.employees
                                            .filter(
                                                (e) =>
                                                    String(e.department_id) ===
                                                    value,
                                            )
                                            .map((e) => e.id);

                                        setPeople((prev) => [
                                            ...new Set([...prev, ...ids]),
                                        ]);
                                    }}
                                    options={[
                                        {
                                            value: 'none',
                                            label: 'Add a whole department',
                                        },
                                        ...mode.departments.map((d) => ({
                                            value: String(d.id),
                                            label: d.name,
                                        })),
                                    ]}
                                    className="sm:w-56"
                                />
                            </div>
                            <ul className="mt-2 max-h-52 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                                {matches.length === 0 && (
                                    <li className="px-3 py-5 text-center text-sm text-muted-foreground">
                                        No one matches that search.
                                    </li>
                                )}
                                {matches.map((employee) => (
                                    <li
                                        key={employee.id}
                                        className="flex items-center gap-3 px-3 py-2"
                                    >
                                        <Checkbox
                                            id={`goal-person-${employee.id}`}
                                            checked={people.includes(
                                                employee.id,
                                            )}
                                            onCheckedChange={() =>
                                                setPeople((prev) =>
                                                    prev.includes(employee.id)
                                                        ? prev.filter(
                                                              (v) =>
                                                                  v !==
                                                                  employee.id,
                                                          )
                                                        : [
                                                              ...prev,
                                                              employee.id,
                                                          ],
                                                )
                                            }
                                        />
                                        <label
                                            htmlFor={`goal-person-${employee.id}`}
                                            className="min-w-0 flex-1 cursor-pointer"
                                        >
                                            <span className="block truncate text-sm font-medium">
                                                {employee.full_name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {employee.department ??
                                                    'No department'}
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-1.5 text-xs text-muted-foreground tabular-nums">
                                {people.length === 0
                                    ? 'Nobody chosen yet.'
                                    : `${people.length} chosen.`}
                                {people.length > 0 && (
                                    <button
                                        type="button"
                                        className="ml-2 text-[#08767c] underline-offset-2 hover:underline dark:text-[#0ABFBF]"
                                        onClick={() => setPeople([])}
                                    >
                                        Clear
                                    </button>
                                )}
                            </p>
                        </FormField>
                    )}

                    {mode.kind !== 'edit' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                label="Review cycle"
                                required
                                error={errors.evaluation_period_id}
                            >
                                <FormSelect
                                    value={periodId}
                                    onChange={setPeriodId}
                                    options={periods.map((p) => ({
                                        value: String(p.id),
                                        label: p.name,
                                    }))}
                                />
                            </FormField>
                            <FormField
                                label="Start from the library"
                                hint="Copies its wording and target."
                            >
                                <FormSelect
                                    value={form.templateId}
                                    onChange={pickTemplate}
                                    options={[
                                        {
                                            value: 'none',
                                            label: 'Write it out',
                                        },
                                        ...templates.map((t) => ({
                                            value: String(t.id),
                                            label: t.name,
                                        })),
                                    ]}
                                />
                            </FormField>
                        </div>
                    )}

                    <FormField label="Goal" required error={errors.title}>
                        <Input
                            value={form.title}
                            onChange={(e) => set('title', e.target.value)}
                            placeholder="e.g. Close 40 new accounts"
                            maxLength={255}
                        />
                    </FormField>

                    <FormField
                        label="What success looks like"
                        error={errors.description}
                    >
                        <textarea
                            value={form.description}
                            onChange={(e) => set('description', e.target.value)}
                            rows={2}
                            maxLength={2000}
                            className={TEXTAREA}
                        />
                    </FormField>

                    <FormField label="Measured as" group>
                        <div className="flex flex-wrap gap-2">
                            {(
                                [
                                    ['percent', 'Progress to 100%'],
                                    ['number', 'A number to reach'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => set('measure', value)}
                                    aria-pressed={form.measure === value}
                                    className={cn(
                                        'min-h-9 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                        form.measure === value
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 text-foreground'
                                            : 'border-border text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </FormField>

                    {form.measure === 'number' && (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <FormField
                                label="Starts at"
                                error={errors.start_value}
                            >
                                <Input
                                    type="number"
                                    inputMode="decimal"
                                    value={form.start}
                                    onChange={(e) =>
                                        set('start', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Target"
                                required
                                error={errors.target_value}
                            >
                                <Input
                                    type="number"
                                    inputMode="decimal"
                                    value={form.target}
                                    onChange={(e) =>
                                        set('target', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Unit"
                                hint="deals, PHP, tickets…"
                                className="col-span-2 sm:col-span-1"
                            >
                                <Input
                                    value={form.unit}
                                    onChange={(e) =>
                                        set('unit', e.target.value)
                                    }
                                    maxLength={40}
                                />
                            </FormField>
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <FormField
                            label="Weight"
                            hint="How much it counts among their goals. 1 is ordinary."
                            error={errors.weight}
                        >
                            <Input
                                type="number"
                                inputMode="decimal"
                                min={0.1}
                                step={0.5}
                                value={form.weight}
                                onChange={(e) => set('weight', e.target.value)}
                            />
                        </FormField>
                        <FormField label="Due" error={errors.due_on}>
                            <Input
                                type="date"
                                value={form.due}
                                onChange={(e) => set('due', e.target.value)}
                            />
                        </FormField>
                    </div>
                </ModalBody>

                <ModalFooter>
                    <Button
                        variant="outline"
                        onClick={() => close(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={processing || blocked}>
                        {processing && <Spinner />}
                        {mode.kind === 'edit'
                            ? 'Save goal'
                            : mode.kind === 'own'
                              ? 'Add goal'
                              : people.length > 1
                                ? `Set for ${people.length} people`
                                : 'Set goal'}
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}

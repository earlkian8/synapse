import { useForm } from '@inertiajs/react';
import {
    BriefcaseBusiness,
    ChevronDown,
    ChevronUp,
    Plus,
    Trash2,
} from 'lucide-react';
import { useId, useMemo } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    EMPLOYMENT_TYPE_OPTIONS,
    POSTING_STATUS_OPTIONS,
    SALARY_PERIOD_OPTIONS,
    WORK_ARRANGEMENT_OPTIONS,
} from '../constants';
import { useSkillCatalogue } from '../hooks/use-skill-catalogue';
import { recruitmentRoutes } from '../routes';
import type {
    EmploymentType,
    ManagedPosting,
    PostingOptions,
    PostingStatus,
    SalaryPeriod,
    WorkArrangement,
} from '../types';
import { SkillsInput } from './skills-input';

type ScreeningQuestionDraft = { label: string };

type Props = {
    posting: ManagedPosting | null;
    options: PostingOptions;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

function str(value: string | number | null | undefined): string {
    return value === null || value === undefined ? '' : String(value);
}

export function PostingFormDialog({
    posting,
    options,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(posting);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="xl">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <BriefcaseBusiness />
                        </ModalIcon>
                    }
                    title={isEditing ? 'Edit posting' : 'New job posting'}
                    description={
                        isEditing
                            ? 'Changes show on the careers page straight away.'
                            : 'Describe the role, then publish it to start taking applications.'
                    }
                />

                {open && (
                    <FormBody
                        key={posting?.id ?? 'new'}
                        posting={posting}
                        options={options}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    posting,
    options,
    onDone,
}: {
    posting: ManagedPosting | null;
    options: PostingOptions;
    onDone: () => void;
}) {
    const isEditing = Boolean(posting);

    const defaultPipeline = useMemo(
        () =>
            options.pipelines.find((p) => p.is_default) ?? options.pipelines[0],
        [options.pipelines],
    );

    const { data, setData, post, processing, errors, transform } = useForm({
        title: posting?.title ?? '',
        recruitment_pipeline_id: str(
            posting?.recruitment_pipeline_id ?? defaultPipeline?.id,
        ),
        department_id: str(posting?.department_id),
        position_id: str(posting?.position_id),
        employment_type: posting?.employment_type ?? 'regular',
        openings: str(posting?.openings ?? 1),
        salary_min: str(posting?.salary_min),
        salary_max: str(posting?.salary_max),
        salary_period: posting?.salary_period ?? ('monthly' as SalaryPeriod),
        show_salary: posting?.show_salary ?? true,
        work_arrangement:
            posting?.work_arrangement ?? ('onsite' as WorkArrangement),
        work_location_id: str(posting?.work_location_id),
        status: posting?.status ?? 'open',
        closing_date: posting?.closing_date ?? '',
        min_years_experience: str(posting?.min_years_experience),
        skills: posting?.skills ?? [],
        preferred_skills: posting?.preferred_skills ?? [],
        requires_resume: posting?.requires_resume ?? true,
        asks_expected_salary: posting?.asks_expected_salary ?? true,
        use_fit_scoring: posting?.use_fit_scoring ?? true,
        screening_questions: (posting?.screening_questions ?? []).map(
            (q): ScreeningQuestionDraft => ({ label: q.label }),
        ),
        description: posting?.description ?? '',
        requirements: posting?.requirements ?? '',
    });

    const catalogue = useSkillCatalogue();
    const closingRequired = data.status === 'open';
    const hasPay = data.salary_min !== '' || data.salary_max !== '';

    const setQuestion = (index: number, label: string) =>
        setData(
            'screening_questions',
            data.screening_questions.map((q, i) =>
                i === index ? { label } : q,
            ),
        );

    const addQuestion = () =>
        setData('screening_questions', [
            ...data.screening_questions,
            { label: '' },
        ]);

    const removeQuestion = (index: number) =>
        setData(
            'screening_questions',
            data.screening_questions.filter((_, i) => i !== index),
        );

    /** Swap a question with its neighbour — the list order is the position. */
    const moveQuestion = (index: number, direction: -1 | 1) => {
        const target = index + direction;

        if (target < 0 || target >= data.screening_questions.length) {
            return;
        }

        const reordered = [...data.screening_questions];
        [reordered[index], reordered[target]] = [
            reordered[target],
            reordered[index],
        ];

        setData('screening_questions', reordered);
    };

    const positions = useMemo(() => {
        const deptId = data.department_id ? Number(data.department_id) : null;

        if (!deptId) {
            return options.positions;
        }

        return options.positions.filter(
            (p) =>
                p.department_id === deptId || String(p.id) === data.position_id,
        );
    }, [data.department_id, data.position_id, options.positions]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => {
            const cleaned: Record<string, unknown> = { ...payload };

            for (const key of [
                'department_id',
                'position_id',
                'work_location_id',
                'closing_date',
                'min_years_experience',
                'salary_min',
                'salary_max',
                'description',
                'requirements',
            ]) {
                if (cleaned[key] === '' || cleaned[key] === NONE) {
                    cleaned[key] = null;
                }
            }

            // A remote role is not based at a site.
            if (payload.work_arrangement === 'remote') {
                cleaned.work_location_id = null;
            }

            cleaned.recruitment_pipeline_id = Number(
                payload.recruitment_pipeline_id,
            );
            cleaned.openings = Number(payload.openings) || 1;
            cleaned.skills = payload.skills
                .map((skill) => skill.trim())
                .filter((skill) => skill.length > 0);
            cleaned.preferred_skills = payload.preferred_skills
                .map((skill) => skill.trim())
                .filter((skill) => skill.length > 0);
            cleaned.screening_questions = payload.screening_questions
                .map((q) => ({ label: q.label.trim() }))
                .filter((q) => q.label.length > 0);

            return cleaned;
        });

        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && posting) {
            post(recruitmentRoutes.update(posting.hashid), opts);
        } else {
            post(recruitmentRoutes.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-7">
                <Section title="Role">
                    <FormField label="Job title" required error={errors.title}>
                        <Input
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="e.g. Payroll Officer"
                            required
                        />
                    </FormField>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Department"
                            error={errors.department_id}
                        >
                            <FormSelect
                                value={data.department_id || NONE}
                                placeholder="Select…"
                                noneValue={NONE}
                                onChange={(v) => {
                                    setData('department_id', v);
                                    setData('position_id', '');
                                }}
                                options={options.departments.map((d) => ({
                                    value: String(d.id),
                                    label: d.name,
                                }))}
                            />
                        </FormField>
                        <FormField label="Position" error={errors.position_id}>
                            <FormSelect
                                value={data.position_id || NONE}
                                placeholder="Select…"
                                noneValue={NONE}
                                onChange={(v) => setData('position_id', v)}
                                options={positions.map((p) => ({
                                    value: String(p.id),
                                    label: p.title,
                                }))}
                            />
                        </FormField>
                        <FormField
                            label="Employment type"
                            required
                            error={errors.employment_type}
                        >
                            <FormSelect
                                value={data.employment_type}
                                onChange={(v) =>
                                    setData(
                                        'employment_type',
                                        v as EmploymentType,
                                    )
                                }
                                options={EMPLOYMENT_TYPE_OPTIONS}
                            />
                        </FormField>
                        <FormField
                            label="Openings"
                            required
                            error={errors.openings}
                        >
                            <Input
                                type="number"
                                min="1"
                                inputMode="numeric"
                                value={data.openings}
                                onChange={(e) =>
                                    setData('openings', e.target.value)
                                }
                                required
                            />
                        </FormField>
                    </div>
                </Section>

                <Section title="Pay & place">
                    <FormField
                        label="Pay range"
                        error={errors.salary_min ?? errors.salary_max}
                        hint="A range, a starting figure, or a cap. Leave it empty if you'd rather not say."
                        group
                    >
                        <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_9rem]">
                            <MoneyInput
                                label="Lowest pay"
                                value={data.salary_min}
                                onChange={(v) => setData('salary_min', v)}
                                placeholder="25,000"
                            />
                            <span className="text-sm text-muted-foreground">
                                to
                            </span>
                            <MoneyInput
                                label="Highest pay"
                                value={data.salary_max}
                                onChange={(v) => setData('salary_max', v)}
                                placeholder="35,000"
                            />
                            <div className="col-span-3 sm:col-span-1">
                                <FormSelect
                                    value={data.salary_period}
                                    onChange={(v) =>
                                        setData(
                                            'salary_period',
                                            v as SalaryPeriod,
                                        )
                                    }
                                    options={SALARY_PERIOD_OPTIONS}
                                    aria-label="Pay is quoted"
                                />
                            </div>
                        </div>
                    </FormField>

                    {hasPay && (
                        <Toggle
                            label="Show the pay on the careers page"
                            hint="Turn off to keep it for your own reference."
                            checked={data.show_salary}
                            onChange={(v) => setData('show_salary', v)}
                        />
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Work arrangement"
                            error={errors.work_arrangement}
                            group
                        >
                            <Segmented
                                label="Work arrangement"
                                value={data.work_arrangement}
                                onChange={(v) => setData('work_arrangement', v)}
                                options={WORK_ARRANGEMENT_OPTIONS}
                            />
                        </FormField>
                        {data.work_arrangement !== 'remote' && (
                            <FormField
                                label="Based at"
                                error={errors.work_location_id}
                                hint={
                                    options.locations.length === 0
                                        ? 'Add your sites under Company Setup → Locations.'
                                        : undefined
                                }
                            >
                                <FormSelect
                                    value={data.work_location_id || NONE}
                                    placeholder="Select a site…"
                                    noneValue={NONE}
                                    onChange={(v) =>
                                        setData('work_location_id', v)
                                    }
                                    options={options.locations.map((l) => ({
                                        value: String(l.id),
                                        label: l.name,
                                    }))}
                                />
                            </FormField>
                        )}
                    </div>
                </Section>

                <Section title="About the role">
                    <FormField label="Description" error={errors.description}>
                        <textarea
                            value={data.description ?? ''}
                            onChange={(e) =>
                                setData('description', e.target.value)
                            }
                            rows={5}
                            placeholder="What the role does, who it works with, and what a good week looks like."
                            className={TEXTAREA_CLASS}
                        />
                    </FormField>
                    <FormField label="Requirements" error={errors.requirements}>
                        <textarea
                            value={data.requirements ?? ''}
                            onChange={(e) =>
                                setData('requirements', e.target.value)
                            }
                            rows={4}
                            placeholder="Education, licences and certifications the role needs."
                            className={TEXTAREA_CLASS}
                        />
                    </FormField>
                    <div className="grid gap-4 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <FormField
                            label="Experience"
                            error={errors.min_years_experience}
                            hint="Minimum, in years."
                        >
                            <Input
                                type="number"
                                min="0"
                                max="50"
                                step="0.5"
                                inputMode="decimal"
                                value={data.min_years_experience}
                                onChange={(e) =>
                                    setData(
                                        'min_years_experience',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. 1.5"
                            />
                        </FormField>
                        <FormField
                            label="Must-have skills"
                            error={errors['skills'] ?? errors['skills.0']}
                            hint="Press Enter or a comma after each. Other names count too, and skills that prove one: TypeScript shows JavaScript."
                        >
                            <SkillsInput
                                value={data.skills}
                                onChange={(skills) => setData('skills', skills)}
                                catalogue={catalogue}
                                exclude={data.preferred_skills}
                                placeholder="e.g. React, SQL, Bookkeeping"
                            />
                        </FormField>
                        <FormField
                            label="Nice-to-have skills"
                            error={
                                errors['preferred_skills'] ??
                                errors['preferred_skills.0']
                            }
                            hint="Each counts half as much as a must-have."
                            className="sm:col-start-2"
                        >
                            <SkillsInput
                                value={data.preferred_skills}
                                onChange={(skills) =>
                                    setData('preferred_skills', skills)
                                }
                                catalogue={catalogue}
                                exclude={data.skills}
                                placeholder="e.g. Docker, QuickBooks"
                            />
                        </FormField>
                    </div>
                </Section>

                <Section title="Applications">
                    <FormField
                        label="Hiring pipeline"
                        required
                        error={errors.recruitment_pipeline_id}
                        hint="The stages candidates move through. Pipelines are managed in Company Setup."
                    >
                        <FormSelect
                            value={data.recruitment_pipeline_id}
                            onChange={(v) =>
                                setData('recruitment_pipeline_id', v)
                            }
                            options={options.pipelines.map((p) => ({
                                value: String(p.id),
                                label: p.is_default
                                    ? `${p.name} (default)`
                                    : p.name,
                            }))}
                        />
                    </FormField>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Toggle
                            label="Ask for a résumé"
                            hint="Off for roles where a CV isn't usual."
                            checked={data.requires_resume}
                            onChange={(v) => setData('requires_resume', v)}
                        />
                        <Toggle
                            label="Ask for expected pay"
                            hint={`Asked ${SALARY_PERIOD_OPTIONS.find((o) => o.value === data.salary_period)?.label ?? 'per month'}, like your range.`}
                            checked={data.asks_expected_salary}
                            onChange={(v) => setData('asks_expected_salary', v)}
                        />
                        <Toggle
                            label="Rank candidates by fit"
                            hint="Scores experience and skills against this posting."
                            checked={data.use_fit_scoring}
                            onChange={(v) => setData('use_fit_scoring', v)}
                        />
                    </div>

                    <FormField
                        label="Yes/no questions"
                        error={
                            errors['screening_questions'] ??
                            errors['screening_questions.0.label']
                        }
                        hint="Asked on the application form, e.g. “Can you work night shifts?”"
                        group
                    >
                        {data.screening_questions.length > 0 && (
                            <div className="space-y-2">
                                {data.screening_questions.map((q, index) => (
                                    <QuestionRow
                                        key={index}
                                        index={index}
                                        question={q}
                                        total={data.screening_questions.length}
                                        error={
                                            (errors as Record<string, string>)[
                                                `screening_questions.${index}.label`
                                            ]
                                        }
                                        onChange={(label) =>
                                            setQuestion(index, label)
                                        }
                                        onMove={(direction) =>
                                            moveQuestion(index, direction)
                                        }
                                        onRemove={() => removeQuestion(index)}
                                    />
                                ))}
                            </div>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className={
                                data.screening_questions.length > 0
                                    ? 'mt-2.5'
                                    : undefined
                            }
                            onClick={addQuestion}
                        >
                            <Plus className="size-4" />
                            Add question
                        </Button>
                    </FormField>
                </Section>

                <Section title="Publishing">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Status"
                            required
                            error={errors.status}
                        >
                            <FormSelect
                                value={data.status}
                                onChange={(v) =>
                                    setData('status', v as PostingStatus)
                                }
                                options={POSTING_STATUS_OPTIONS}
                            />
                        </FormField>
                        <FormField
                            label="Closing date"
                            required={closingRequired}
                            error={errors.closing_date}
                            hint={
                                closingRequired
                                    ? 'Applications close at the end of this day.'
                                    : 'Optional while it is a draft.'
                            }
                        >
                            <Input
                                type="date"
                                value={data.closing_date ?? ''}
                                onChange={(e) =>
                                    setData('closing_date', e.target.value)
                                }
                                required={closingRequired}
                            />
                        </FormField>
                    </div>
                </Section>
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
                    {isEditing ? 'Save changes' : 'Create posting'}
                </Button>
            </ModalFooter>
        </form>
    );
}

/** A peso amount — the figure, with ₱ in front of it. */
function MoneyInput({
    label,
    value,
    onChange,
    placeholder,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
}) {
    return (
        <div className="relative">
            <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                ₱
            </span>
            <Input
                type="number"
                min="0"
                step="0.01"
                inputMode="decimal"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                aria-label={label}
                className="pl-7"
            />
        </div>
    );
}

/** A small set of choices shown side by side — one is always picked. */
function Segmented<T extends string>({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: T;
    onChange: (value: T) => void;
    options: { value: T; label: string }[];
}) {
    return (
        <div
            role="radiogroup"
            aria-label={label}
            className="grid h-9 auto-cols-fr grid-flow-col gap-1 rounded-md border border-input p-0.5"
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'rounded-[5px] text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        value === option.value
                            ? 'bg-[#0F2044] font-medium text-white dark:bg-[#0ABFBF] dark:text-[#0F2044]'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

const TEXTAREA_CLASS =
    'flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

function Toggle({
    label,
    hint,
    checked,
    onChange,
}: {
    label: string;
    hint: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const id = useId();

    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border border-border px-3 py-2.5">
            <div className="min-w-0">
                <Label htmlFor={id} className="cursor-pointer">
                    {label}
                </Label>
                <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
            </div>
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onChange}
                className="shrink-0"
            />
        </div>
    );
}

/**
 * One screening question. The row's position *is* the order candidates see
 * it in — the same click-based reorder as the onboarding checklist, no
 * drag-and-drop dependency.
 */
function QuestionRow({
    index,
    question,
    total,
    error,
    onChange,
    onMove,
    onRemove,
}: {
    index: number;
    question: ScreeningQuestionDraft;
    total: number;
    error?: string;
    onChange: (label: string) => void;
    onMove: (direction: -1 | 1) => void;
    onRemove: () => void;
}) {
    const position = `Question ${index + 1}`;

    return (
        <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-2.5">
            <div className="flex shrink-0 flex-col items-center">
                <button
                    type="button"
                    onClick={() => onMove(-1)}
                    disabled={index === 0}
                    className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-30 disabled:hover:text-muted-foreground"
                    aria-label={`Move ${position} up`}
                >
                    <ChevronUp className="size-3.5" />
                </button>
                <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                    {index + 1}
                </span>
                <button
                    type="button"
                    onClick={() => onMove(1)}
                    disabled={index === total - 1}
                    className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-30 disabled:hover:text-muted-foreground"
                    aria-label={`Move ${position} down`}
                >
                    <ChevronDown className="size-3.5" />
                </button>
            </div>

            <div className="flex-1">
                <Input
                    value={question.label}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="e.g. Do you have a valid driver's license?"
                    aria-label={position}
                    aria-invalid={error ? true : undefined}
                />
                <InputError message={error} role="alert" className="mt-1" />
            </div>

            <button
                type="button"
                onClick={onRemove}
                className="mt-1.5 rounded-md p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={`Remove ${position}`}
            >
                <Trash2 className="size-4" />
            </button>
        </div>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-4">
            <h3 className="border-b border-border/70 pb-2 text-sm font-semibold tracking-tight">
                {title}
            </h3>
            {children}
        </section>
    );
}

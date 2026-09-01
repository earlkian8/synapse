import { useForm } from '@inertiajs/react';
import {
    BriefcaseBusiness,
    ChevronDown,
    ChevronUp,
    Plus,
    Trash2,
    X,
} from 'lucide-react';
import { useId, useMemo, useState } from 'react';
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
import { EMPLOYMENT_TYPE_OPTIONS, POSTING_STATUS_OPTIONS } from '../constants';
import { recruitmentRoutes } from '../routes';
import type {
    EmploymentType,
    ManagedPosting,
    PostingOptions,
    PostingStatus,
} from '../types';

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
                            ? 'Update the role, its screening criteria, and how long it stays open.'
                            : 'Open a vacancy to start collecting applications.'
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
        status: posting?.status ?? 'open',
        closing_date: posting?.closing_date ?? '',
        min_years_experience: str(posting?.min_years_experience),
        skills: posting?.skills ?? [],
        requires_resume: posting?.requires_resume ?? true,
        use_fit_scoring: posting?.use_fit_scoring ?? true,
        screening_questions: (posting?.screening_questions ?? []).map(
            (q): ScreeningQuestionDraft => ({ label: q.label }),
        ),
        description: posting?.description ?? '',
        requirements: posting?.requirements ?? '',
    });

    const closingRequired = data.status === 'open';

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
                'closing_date',
                'min_years_experience',
                'description',
                'requirements',
            ]) {
                if (cleaned[key] === '' || cleaned[key] === NONE) {
                    cleaned[key] = null;
                }
            }

            cleaned.recruitment_pipeline_id = Number(
                payload.recruitment_pipeline_id,
            );
            cleaned.openings = Number(payload.openings) || 1;
            cleaned.min_years_experience =
                cleaned.min_years_experience === null
                    ? null
                    : Number(payload.min_years_experience);
            cleaned.skills = payload.skills
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
                <Section title="Role" subtitle="What you are hiring for.">
                    <FormField label="Job title" required error={errors.title}>
                        <Input
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="e.g. Software Engineer"
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
                                    ? 'Applications close on this date.'
                                    : 'Optional while the posting is a draft.'
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

                <Section
                    title="Hiring process"
                    subtitle="Which pipeline this posting runs on, and how candidates apply."
                >
                    <FormField
                        label="Pipeline"
                        required
                        error={errors.recruitment_pipeline_id}
                        hint="The stages a candidate moves through for this role. Manage pipelines from Company Setup."
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
                            label="Require a résumé"
                            hint="Turn off for roles where a CV isn't the norm (e.g. drivers, warehouse staff)."
                            checked={data.requires_resume}
                            onChange={(v) => setData('requires_resume', v)}
                        />
                        <Toggle
                            label="Rank candidates automatically"
                            hint="Scores and sorts applicants by fit. Turn off to review everyone in application order."
                            checked={data.use_fit_scoring}
                            onChange={(v) => setData('use_fit_scoring', v)}
                        />
                    </div>
                </Section>

                <Section
                    title="Screening criteria"
                    subtitle="Optional — sharpens the automatic candidate ranking for this role."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Minimum experience (years)"
                            error={errors.min_years_experience}
                            hint="Candidates at or above this earn full experience marks."
                        >
                            <Input
                                type="number"
                                min="0"
                                max="50"
                                inputMode="numeric"
                                value={data.min_years_experience}
                                onChange={(e) =>
                                    setData(
                                        'min_years_experience',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. 3"
                            />
                        </FormField>
                        <FormField
                            label="Required skills"
                            error={errors['skills'] ?? errors['skills.0']}
                            hint="Press Enter or comma to add. Matched against the candidate's headline, notes and cover letter."
                        >
                            <SkillsInput
                                value={data.skills}
                                onChange={(skills) => setData('skills', skills)}
                            />
                        </FormField>
                    </div>

                    <FormField
                        label="Screening questions"
                        error={
                            errors['screening_questions'] ??
                            errors['screening_questions.0.label']
                        }
                        hint="Yes/No questions candidates answer on the application form — e.g. 'Valid driver's license?' or 'Available for night shift?'"
                        group
                    >
                        {data.screening_questions.length === 0 ? (
                            <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
                                No screening questions yet.
                            </p>
                        ) : (
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
                            className="mt-2.5"
                            onClick={addQuestion}
                        >
                            <Plus className="size-4" />
                            Add question
                        </Button>
                    </FormField>
                </Section>

                <Section
                    title="Details"
                    subtitle="The job description and requirements, as candidates will read them."
                >
                    <div className="grid gap-4 lg:grid-cols-2">
                        <FormField
                            label="Description"
                            error={errors.description}
                        >
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
                        <FormField
                            label="Requirements"
                            error={errors.requirements}
                        >
                            <textarea
                                value={data.requirements ?? ''}
                                onChange={(e) =>
                                    setData('requirements', e.target.value)
                                }
                                rows={5}
                                placeholder="Qualifications, licences, and the experience a candidate needs."
                                className={TEXTAREA_CLASS}
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
    subtitle,
    children,
}: {
    title: string;
    subtitle: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div className="border-b border-border/70 pb-2.5">
                <h3 className="text-sm font-semibold tracking-tight">
                    {title}
                </h3>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {subtitle}
                </p>
            </div>
            {children}
        </section>
    );
}

/**
 * A lightweight tag input for the posting's required skills. Enter or comma
 * commits the current token; Backspace on an empty field removes the last chip.
 */
function SkillsInput({
    value,
    onChange,
    ...props
}: {
    value: string[];
    onChange: (skills: string[]) => void;
    id?: string;
    'aria-describedby'?: string;
}) {
    const [draft, setDraft] = useState('');

    const add = (raw: string) => {
        const skill = raw.trim().replace(/,$/, '').trim();

        if (
            skill &&
            !value.some((s) => s.toLowerCase() === skill.toLowerCase())
        ) {
            onChange([...value, skill]);
        }

        setDraft('');
    };

    const remove = (index: number) =>
        onChange(value.filter((_, i) => i !== index));

    return (
        <div className="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent px-2 py-1.5 focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30">
            {value.map((skill, index) => (
                <span
                    key={`${skill}-${index}`}
                    className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-xs font-medium"
                >
                    {skill}
                    <button
                        type="button"
                        onClick={() => remove(index)}
                        className="rounded-sm text-muted-foreground hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label={`Remove ${skill}`}
                    >
                        <X className="size-3" />
                    </button>
                </span>
            ))}
            <input
                {...props}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        add(draft);
                    } else if (
                        e.key === 'Backspace' &&
                        draft === '' &&
                        value.length > 0
                    ) {
                        remove(value.length - 1);
                    }
                }}
                onBlur={() => draft && add(draft)}
                placeholder={
                    value.length === 0 ? 'e.g. React, SQL, Laravel' : ''
                }
                className="min-w-32 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            />
        </div>
    );
}

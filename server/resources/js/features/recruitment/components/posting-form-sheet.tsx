import { useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { EMPLOYMENT_TYPE_OPTIONS, POSTING_STATUS_OPTIONS } from '../constants';
import { recruitmentRoutes } from '../routes';
import type {
    EmploymentType,
    ManagedPosting,
    PostingOptions,
    PostingStatus,
} from '../types';

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

export function PostingFormSheet({
    posting,
    options,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(posting);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit posting' : 'New job posting'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update this job posting.'
                            : 'Open a vacancy to start collecting applications.'}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={posting?.id ?? 'new'}
                        posting={posting}
                        options={options}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
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

    const { data, setData, post, processing, errors, transform } = useForm({
        title: posting?.title ?? '',
        department_id: str(posting?.department_id),
        position_id: str(posting?.position_id),
        employment_type: posting?.employment_type ?? 'regular',
        openings: str(posting?.openings ?? 1),
        status: posting?.status ?? 'open',
        closing_date: posting?.closing_date ?? '',
        min_years_experience: str(posting?.min_years_experience),
        skills: posting?.skills ?? [],
        description: posting?.description ?? '',
        requirements: posting?.requirements ?? '',
    });

    const closingRequired = data.status === 'open';

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

            cleaned.openings = Number(payload.openings) || 1;
            cleaned.min_years_experience =
                cleaned.min_years_experience === null
                    ? null
                    : Number(payload.min_years_experience);
            cleaned.skills = payload.skills
                .map((skill) => skill.trim())
                .filter((skill) => skill.length > 0);

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
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-8 px-6 py-6">
                <Section title="Role" subtitle="What you are hiring for.">
                    <Field label="Job title" required error={errors.title}>
                        <Input
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="e.g. Software Engineer"
                            required
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Department" error={errors.department_id}>
                            <FkSelect
                                value={data.department_id || NONE}
                                placeholder="Select…"
                                onChange={(v) => {
                                    setData('department_id', v);
                                    setData('position_id', '');
                                }}
                                options={options.departments.map((d) => ({
                                    value: String(d.id),
                                    label: d.name,
                                }))}
                            />
                        </Field>
                        <Field label="Position" error={errors.position_id}>
                            <FkSelect
                                value={data.position_id || NONE}
                                placeholder="Select…"
                                onChange={(v) => setData('position_id', v)}
                                options={positions.map((p) => ({
                                    value: String(p.id),
                                    label: p.title,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Employment type"
                            required
                            error={errors.employment_type}
                        >
                            <FkSelect
                                value={data.employment_type}
                                onChange={(v) =>
                                    setData(
                                        'employment_type',
                                        v as EmploymentType,
                                    )
                                }
                                options={EMPLOYMENT_TYPE_OPTIONS}
                            />
                        </Field>
                        <Field
                            label="Openings"
                            required
                            error={errors.openings}
                        >
                            <Input
                                type="number"
                                min="1"
                                value={data.openings}
                                onChange={(e) =>
                                    setData('openings', e.target.value)
                                }
                                required
                            />
                        </Field>
                        <Field label="Status" required error={errors.status}>
                            <FkSelect
                                value={data.status}
                                onChange={(v) =>
                                    setData('status', v as PostingStatus)
                                }
                                options={POSTING_STATUS_OPTIONS}
                            />
                        </Field>
                        <Field
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
                        </Field>
                    </div>
                </Section>

                <Section
                    title="Screening criteria"
                    subtitle="Optional — sharpens the automatic candidate ranking for this role."
                >
                    <Field
                        label="Minimum experience (years)"
                        error={errors.min_years_experience}
                        hint="Candidates at or above this earn full experience marks."
                    >
                        <Input
                            type="number"
                            min="0"
                            max="50"
                            value={data.min_years_experience}
                            onChange={(e) =>
                                setData('min_years_experience', e.target.value)
                            }
                            placeholder="e.g. 3"
                        />
                    </Field>
                    <Field
                        label="Required skills"
                        error={errors['skills'] ?? errors['skills.0']}
                        hint="Press Enter or comma to add. Matched against the candidate's headline, notes and cover letter."
                    >
                        <SkillsInput
                            value={data.skills}
                            onChange={(skills) => setData('skills', skills)}
                        />
                    </Field>
                </Section>

                <Section
                    title="Details"
                    subtitle="The job description and requirements."
                >
                    <Field label="Description" error={errors.description}>
                        <textarea
                            value={data.description ?? ''}
                            onChange={(e) =>
                                setData('description', e.target.value)
                            }
                            rows={4}
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                        />
                    </Field>
                    <Field label="Requirements" error={errors.requirements}>
                        <textarea
                            value={data.requirements ?? ''}
                            onChange={(e) =>
                                setData('requirements', e.target.value)
                            }
                            rows={4}
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                        />
                    </Field>
                </Section>
            </div>

            <SheetFooter className="border-t border-border px-6 py-4">
                <div className="flex w-full items-center justify-end gap-2">
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
                </div>
            </SheetFooter>
        </form>
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
            <div>
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-xs text-muted-foreground">{subtitle}</p>
            </div>
            {children}
        </section>
    );
}

function Field({
    label,
    required = false,
    error,
    hint,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <Label className="mb-1.5 block">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            {hint && !error && (
                <p className="mt-1.5 text-xs text-muted-foreground">{hint}</p>
            )}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}

/**
 * A lightweight tag input for the posting's required skills. Enter or comma
 * commits the current token; Backspace on an empty field removes the last chip.
 */
function SkillsInput({
    value,
    onChange,
}: {
    value: string[];
    onChange: (skills: string[]) => void;
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
                        className="text-muted-foreground hover:text-destructive"
                        aria-label={`Remove ${skill}`}
                    >
                        <X className="size-3" />
                    </button>
                </span>
            ))}
            <input
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
                className="min-w-[8rem] flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            />
        </div>
    );
}

function FkSelect({
    value,
    placeholder,
    onChange,
    options,
}: {
    value: string;
    placeholder?: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="w-full">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {placeholder && (
                    <SelectItem value={NONE}>{placeholder}</SelectItem>
                )}
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

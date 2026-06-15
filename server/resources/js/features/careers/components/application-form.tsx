import { useForm } from '@inertiajs/react';
import { Paperclip, Plus, Upload, X } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { CareersOrg, DocumentTypeOption, PostingDetail } from '../types';

type DocumentRow = { type: string; file: File | null };

type ApplicationData = {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    current_location: string;
    headline: string;
    linkedin_url: string;
    portfolio_url: string;
    years_experience: string;
    expected_salary: string;
    cover_note: string;
    resume: File | null;
    documents: DocumentRow[];
    hp_field: string;
};

export function ApplicationForm({
    organization,
    posting,
    documentTypes,
}: {
    organization: CareersOrg;
    posting: PostingDetail;
    documentTypes: DocumentTypeOption[];
}) {
    const { data, setData, post, processing, errors, transform } =
        useForm<ApplicationData>({
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            current_location: '',
            headline: '',
            linkedin_url: '',
            portfolio_url: '',
            years_experience: '',
            expected_salary: '',
            cover_note: '',
            resume: null,
            documents: [],
            hp_field: '',
        });

    const addDocument = () =>
        setData('documents', [
            ...data.documents,
            { type: documentTypes[0]?.value ?? 'other', file: null },
        ]);

    const updateDocument = (index: number, patch: Partial<DocumentRow>) =>
        setData(
            'documents',
            data.documents.map((row, i) =>
                i === index ? { ...row, ...patch } : row,
            ),
        );

    const removeDocument = (index: number) =>
        setData(
            'documents',
            data.documents.filter((_, i) => i !== index),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        // Drop empty document rows before they reach the server.
        transform((payload) => ({
            ...payload,
            documents: payload.documents.filter((row) => row.file),
        }));

        post(`/careers/${organization.slug}/jobs/${posting.hashid}/apply`, {
            forceFormData: true,
        });
    };

    // Inertia flattens nested errors (e.g. "documents.0.file").
    const documentError = Object.entries(errors).find(([key]) =>
        key.startsWith('documents'),
    )?.[1];

    return (
        <form onSubmit={submit} className="space-y-8">
            {/* Honeypot — hidden from humans, tempting to bots. */}
            <input
                type="text"
                name="hp_field"
                value={data.hp_field}
                onChange={(e) => setData('hp_field', e.target.value)}
                tabIndex={-1}
                autoComplete="off"
                aria-hidden="true"
                className="hidden"
            />

            <Section
                title="About you"
                description="Tell us who you are and how to reach you."
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="First name"
                        required
                        error={errors.first_name}
                    >
                        <Input
                            value={data.first_name}
                            onChange={(e) =>
                                setData('first_name', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Last name" required error={errors.last_name}>
                        <Input
                            value={data.last_name}
                            onChange={(e) =>
                                setData('last_name', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Email" required error={errors.email}>
                        <Input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    </Field>
                    <Field label="Phone" error={errors.phone}>
                        <Input
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                        />
                    </Field>
                    <Field
                        label="Current location"
                        error={errors.current_location}
                    >
                        <Input
                            value={data.current_location}
                            onChange={(e) =>
                                setData('current_location', e.target.value)
                            }
                            placeholder="City, Province"
                        />
                    </Field>
                    <Field
                        label="Years of experience"
                        error={errors.years_experience}
                    >
                        <Input
                            type="number"
                            min="0"
                            max="60"
                            value={data.years_experience}
                            onChange={(e) =>
                                setData('years_experience', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Headline"
                        error={errors.headline}
                        className="sm:col-span-2"
                    >
                        <Input
                            value={data.headline}
                            onChange={(e) =>
                                setData('headline', e.target.value)
                            }
                            placeholder="e.g. Senior Accountant with 5 years in payroll"
                        />
                    </Field>
                    <Field label="LinkedIn URL" error={errors.linkedin_url}>
                        <Input
                            type="url"
                            value={data.linkedin_url}
                            onChange={(e) =>
                                setData('linkedin_url', e.target.value)
                            }
                            placeholder="https://linkedin.com/in/…"
                        />
                    </Field>
                    <Field label="Portfolio URL" error={errors.portfolio_url}>
                        <Input
                            type="url"
                            value={data.portfolio_url}
                            onChange={(e) =>
                                setData('portfolio_url', e.target.value)
                            }
                            placeholder="https://…"
                        />
                    </Field>
                </div>
            </Section>

            <Section
                title="Your application"
                description="Attach your CV and tell us why you're a fit."
            >
                <Field label="Résumé / CV" required error={errors.resume}>
                    <FileInput
                        file={data.resume}
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                        onSelect={(file) => setData('resume', file)}
                        onClear={() => setData('resume', null)}
                    />
                    <p className="mt-1.5 text-xs text-slate-500">
                        PDF, DOC, DOCX, JPG or PNG · up to 10MB.
                    </p>
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Expected salary (₱)"
                        error={errors.expected_salary}
                    >
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.expected_salary}
                            onChange={(e) =>
                                setData('expected_salary', e.target.value)
                            }
                        />
                    </Field>
                </div>

                <Field label="Cover note" error={errors.cover_note}>
                    <textarea
                        value={data.cover_note}
                        onChange={(e) => setData('cover_note', e.target.value)}
                        rows={4}
                        placeholder="A short note to the hiring team (optional)."
                        className="flex w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-[#0ABFBF] focus-visible:ring-2 focus-visible:ring-[#0ABFBF]/30"
                    />
                </Field>
            </Section>

            <Section
                title="Supporting documents"
                description="Optional — cover letter, certificates, transcript, portfolio, ID, etc."
            >
                <div className="space-y-3">
                    {data.documents.map((row, index) => (
                        <div
                            key={index}
                            className="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:flex-row sm:items-center"
                        >
                            <select
                                value={row.type}
                                onChange={(e) =>
                                    updateDocument(index, {
                                        type: e.target.value,
                                    })
                                }
                                className="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm outline-none focus-visible:border-[#0ABFBF] sm:w-48"
                            >
                                {documentTypes.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                            <div className="flex-1">
                                <FileInput
                                    file={row.file}
                                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                    onSelect={(file) =>
                                        updateDocument(index, { file })
                                    }
                                    onClear={() =>
                                        updateDocument(index, { file: null })
                                    }
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={() => removeDocument(index)}
                                aria-label="Remove document"
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                    ))}

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addDocument}
                    >
                        <Plus className="size-4" />
                        Add document
                    </Button>
                    <InputError message={documentError} className="mt-1" />
                </div>
            </Section>

            <div className="flex items-center justify-end border-t border-slate-200 pt-6">
                <Button
                    type="submit"
                    disabled={processing}
                    className="bg-[#0ABFBF] text-[#0F2044] hover:bg-[#09aeae]"
                >
                    {processing ? (
                        <Spinner />
                    ) : (
                        <Paperclip className="size-4" />
                    )}
                    Submit application
                </Button>
            </div>
        </form>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div>
                <h2 className="text-base font-semibold text-slate-900">
                    {title}
                </h2>
                {description && (
                    <p className="text-sm text-slate-500">{description}</p>
                )}
            </div>
            {children}
        </section>
    );
}

function Field({
    label,
    required = false,
    error,
    className,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={className}>
            <Label className="mb-1.5 block text-slate-700">
                {label}
                {required && <span className="ml-0.5 text-rose-500">*</span>}
            </Label>
            {children}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}

function FileInput({
    file,
    accept,
    onSelect,
    onClear,
}: {
    file: File | null;
    accept: string;
    onSelect: (file: File | null) => void;
    onClear: () => void;
}) {
    if (file) {
        return (
            <div className="flex items-center justify-between rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm">
                <span className="truncate text-slate-700">{file.name}</span>
                <button
                    type="button"
                    onClick={onClear}
                    className="ml-2 text-slate-400 hover:text-slate-700"
                    aria-label="Remove file"
                >
                    <X className="size-4" />
                </button>
            </div>
        );
    }

    return (
        <label className="flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-slate-300 bg-white px-3 py-2 text-sm text-slate-500 hover:border-[#0ABFBF] hover:text-slate-700">
            <Upload className="size-4" />
            <span>Choose a file…</span>
            <input
                type="file"
                accept={accept}
                onChange={(e) => onSelect(e.target.files?.[0] ?? null)}
                className="hidden"
            />
        </label>
    );
}

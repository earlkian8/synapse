import { useForm } from '@inertiajs/react';
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
import { SOURCE_OPTIONS } from '../constants';
import { recruitmentRoutes } from '../routes';
import type { ApplicantSource, PipelineOptions } from '../types';
import { RatingStars } from './rating-stars';

type Props = {
    postingId: number;
    options: PipelineOptions;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NEW = '__new__';

export function AddCandidateSheet({
    postingId,
    options,
    open,
    onOpenChange,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>Add candidate</SheetTitle>
                    <SheetDescription>
                        Add an existing applicant or create a new one for this
                        posting.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        postingId={postingId}
                        options={options}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    postingId,
    options,
    onDone,
}: {
    postingId: number;
    options: PipelineOptions;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        applicant_id: NEW,
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        headline: '',
        source: 'website' as ApplicantSource,
        expected_salary: '',
        cover_note: '',
        rating: null as number | null,
    });

    const isNew = data.applicant_id === NEW;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => {
            const cleaned: Record<string, unknown> = { ...payload };

            if (isNew) {
                cleaned.applicant_id = null;
            } else {
                cleaned.first_name = null;
                cleaned.last_name = null;
                cleaned.email = null;
                cleaned.phone = null;
                cleaned.headline = null;
            }

            for (const key of ['expected_salary', 'cover_note']) {
                if (cleaned[key] === '') {
                    cleaned[key] = null;
                }
            }

            return cleaned;
        });

        post(recruitmentRoutes.applications(postingId), {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label className="mb-1.5 block">Candidate</Label>
                    <Select
                        value={data.applicant_id}
                        onValueChange={(v) => setData('applicant_id', v)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NEW}>
                                + New candidate
                            </SelectItem>
                            {options.applicants.map((a) => (
                                <SelectItem key={a.id} value={String(a.id)}>
                                    {a.full_name}
                                    {a.headline ? ` · ${a.headline}` : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {isNew && (
                    <div className="grid gap-4 rounded-lg border border-border bg-muted/30 p-4 sm:grid-cols-2">
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
                        <Field
                            label="Last name"
                            required
                            error={errors.last_name}
                        >
                            <Input
                                value={data.last_name}
                                onChange={(e) =>
                                    setData('last_name', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Email" error={errors.email}>
                            <Input
                                type="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Phone" error={errors.phone}>
                            <Input
                                value={data.phone}
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Headline" error={errors.headline}>
                            <Input
                                value={data.headline}
                                onChange={(e) =>
                                    setData('headline', e.target.value)
                                }
                                placeholder="Current role"
                            />
                        </Field>
                        <Field label="Source" error={errors.source}>
                            <Select
                                value={data.source}
                                onValueChange={(v) =>
                                    setData('source', v as ApplicantSource)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SOURCE_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Expected salary"
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
                    <Field label="Rating" error={errors.rating}>
                        <div className="flex h-9 items-center">
                            <RatingStars
                                size="md"
                                value={data.rating}
                                onChange={(v) => setData('rating', v)}
                            />
                        </div>
                    </Field>
                </div>

                <Field label="Cover note" error={errors.cover_note}>
                    <textarea
                        value={data.cover_note}
                        onChange={(e) => setData('cover_note', e.target.value)}
                        rows={3}
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </Field>
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
                        Add to pipeline
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

function Field({
    label,
    required = false,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <Label className="mb-1.5 block">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}

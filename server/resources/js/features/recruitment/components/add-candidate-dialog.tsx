import { useForm } from '@inertiajs/react';
import { UserRoundPlus } from 'lucide-react';
import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { SOURCE_LABELS, SOURCE_OPTIONS } from '../constants';
import { recruitmentRoutes } from '../routes';
import type { ApplicantSource, PipelineOptions } from '../types';
import { FkSelect } from './fk-select';
import { FormField } from './form-field';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from './modal';
import { RatingStars } from './rating-stars';

type Props = {
    postingId: string;
    options: PipelineOptions;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NEW = '__new__';

export function AddCandidateDialog({
    postingId,
    options,
    open,
    onOpenChange,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <UserRoundPlus />
                        </ModalIcon>
                    }
                    title="Add candidate"
                    description="Pick someone already in the candidate pool, or create a new applicant for this posting."
                />

                {open && (
                    <FormBody
                        postingId={postingId}
                        options={options}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    postingId,
    options,
    onDone,
}: {
    postingId: string;
    options: PipelineOptions;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        applicant_id: NEW,
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        current_location: '',
        headline: '',
        linkedin_url: '',
        portfolio_url: '',
        years_experience: '',
        source: 'website' as ApplicantSource,
        expected_salary: '',
        cover_note: '',
        rating: null as number | null,
    });

    const isNew = data.applicant_id === NEW;

    const selected = useMemo(
        () =>
            isNew
                ? null
                : (options.applicants.find(
                      (a) => String(a.id) === data.applicant_id,
                  ) ?? null),
        [isNew, data.applicant_id, options.applicants],
    );

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
                cleaned.current_location = null;
                cleaned.headline = null;
                cleaned.linkedin_url = null;
                cleaned.portfolio_url = null;
                cleaned.years_experience = null;
            }

            for (const key of [
                'expected_salary',
                'cover_note',
                'current_location',
                'linkedin_url',
                'portfolio_url',
                'years_experience',
            ]) {
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
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-6">
                <FormField
                    label="Candidate"
                    error={errors.applicant_id}
                    hint="Adding an existing candidate keeps their history in one profile."
                >
                    <FkSelect
                        value={data.applicant_id}
                        onChange={(v) => setData('applicant_id', v)}
                        options={[
                            { value: NEW, label: '+ New candidate' },
                            ...options.applicants.map((a) => ({
                                value: String(a.id),
                                label: a.headline
                                    ? `${a.full_name} · ${a.headline}`
                                    : a.full_name,
                            })),
                        ]}
                    />
                </FormField>

                {selected && (
                    <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/30 p-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0F2044] text-xs font-semibold text-white">
                            {selected.initials}
                        </span>
                        <div className="min-w-0 text-sm">
                            <p className="truncate font-medium">
                                {selected.full_name}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {[
                                    selected.headline,
                                    selected.email,
                                    `via ${SOURCE_LABELS[selected.source]}`,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>
                    </div>
                )}

                {isNew && (
                    <fieldset className="rounded-lg border border-border bg-muted/30 px-4 pt-2 pb-4">
                        <legend className="px-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            New candidate
                        </legend>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                label="First name"
                                required
                                error={errors.first_name}
                            >
                                <Input
                                    value={data.first_name}
                                    autoComplete="given-name"
                                    onChange={(e) =>
                                        setData('first_name', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Last name"
                                required
                                error={errors.last_name}
                            >
                                <Input
                                    value={data.last_name}
                                    autoComplete="family-name"
                                    onChange={(e) =>
                                        setData('last_name', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField label="Email" error={errors.email}>
                                <Input
                                    type="email"
                                    value={data.email}
                                    autoComplete="email"
                                    onChange={(e) =>
                                        setData('email', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField label="Phone" error={errors.phone}>
                                <Input
                                    type="tel"
                                    value={data.phone}
                                    autoComplete="tel"
                                    onChange={(e) =>
                                        setData('phone', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField label="Headline" error={errors.headline}>
                                <Input
                                    value={data.headline}
                                    onChange={(e) =>
                                        setData('headline', e.target.value)
                                    }
                                    placeholder="Current role"
                                />
                            </FormField>
                            <FormField
                                label="Location"
                                error={errors.current_location}
                            >
                                <Input
                                    value={data.current_location}
                                    onChange={(e) =>
                                        setData(
                                            'current_location',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="City, Province"
                                />
                            </FormField>
                            <FormField
                                label="Years of experience"
                                error={errors.years_experience}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    max="60"
                                    inputMode="numeric"
                                    value={data.years_experience}
                                    onChange={(e) =>
                                        setData(
                                            'years_experience',
                                            e.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField label="Source" error={errors.source}>
                                <FkSelect
                                    value={data.source}
                                    onChange={(v) =>
                                        setData('source', v as ApplicantSource)
                                    }
                                    options={SOURCE_OPTIONS}
                                />
                            </FormField>
                            <FormField
                                label="LinkedIn URL"
                                error={errors.linkedin_url}
                            >
                                <Input
                                    type="url"
                                    value={data.linkedin_url}
                                    onChange={(e) =>
                                        setData('linkedin_url', e.target.value)
                                    }
                                    placeholder="https://linkedin.com/in/…"
                                />
                            </FormField>
                            <FormField
                                label="Portfolio URL"
                                error={errors.portfolio_url}
                            >
                                <Input
                                    type="url"
                                    value={data.portfolio_url}
                                    onChange={(e) =>
                                        setData('portfolio_url', e.target.value)
                                    }
                                    placeholder="https://…"
                                />
                            </FormField>
                        </div>
                    </fieldset>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Expected salary"
                        error={errors.expected_salary}
                        hint="Monthly, in pesos."
                    >
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            inputMode="decimal"
                            value={data.expected_salary}
                            onChange={(e) =>
                                setData('expected_salary', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField label="Rating" error={errors.rating} group>
                        <div className="flex h-9 items-center">
                            <RatingStars
                                size="md"
                                value={data.rating}
                                onChange={(v) => setData('rating', v)}
                            />
                        </div>
                    </FormField>
                </div>

                <FormField label="Cover note" error={errors.cover_note}>
                    <textarea
                        value={data.cover_note}
                        onChange={(e) => setData('cover_note', e.target.value)}
                        rows={3}
                        placeholder="Anything the hiring team should know before screening."
                        className="flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 aria-invalid:border-destructive aria-invalid:ring-destructive/20"
                    />
                </FormField>
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
                    Add to pipeline
                </Button>
            </ModalFooter>
        </form>
    );
}

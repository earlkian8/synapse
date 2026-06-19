import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, Contact, Landmark, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { companyProfileRoutes } from '@/features/company-profile/routes';
import type { CompanyProfilePageProps } from '@/features/company-profile/types';

export default function CompanyProfilePage() {
    const { company, can } = usePage<CompanyProfilePageProps>().props;
    const readOnly = !can.manage;

    const { data, setData, post, processing, errors, isDirty, transform } =
        useForm({
            name: company.name ?? '',
            legal_name: company.legal_name ?? '',
            email: company.email ?? '',
            phone: company.phone ?? '',
            address: company.address ?? '',
            tin: company.tin ?? '',
            sss_employer_no: company.sss_employer_no ?? '',
            philhealth_employer_no: company.philhealth_employer_no ?? '',
            pagibig_employer_no: company.pagibig_employer_no ?? '',
            logo: null as File | null,
            remove_logo: false,
        });

    const fileInput = useRef<HTMLInputElement>(null);
    const [logoPreview, setLogoPreview] = useState<string | null>(
        company.logo_url,
    );

    const pickLogo = (file: File | null) => {
        if (!file) {
            return;
        }

        if (logoPreview?.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreview);
        }

        setData('logo', file);
        setData('remove_logo', false);
        setLogoPreview(URL.createObjectURL(file));
    };

    const removeLogo = () => {
        if (logoPreview?.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreview);
        }

        setData('logo', null);
        setData('remove_logo', true);
        setLogoPreview(null);

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    const logoChanged = data.logo !== null || data.remove_logo;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            legal_name: payload.legal_name || null,
            email: payload.email || null,
            phone: payload.phone || null,
            address: payload.address || null,
            tin: payload.tin || null,
            sss_employer_no: payload.sss_employer_no || null,
            philhealth_employer_no: payload.philhealth_employer_no || null,
            pagibig_employer_no: payload.pagibig_employer_no || null,
        }));

        post(companyProfileRoutes.update, {
            preserveScroll: true,
            onSuccess: () => {
                setData('logo', null);
                setData('remove_logo', false);
            },
        });
    };

    return (
        <>
            <Head title="Company Profile" />

            <form
                onSubmit={submit}
                className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-5 p-4 md:p-6"
            >
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Company Profile
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Your organisation's identity, contact details and
                        statutory employer numbers — used across documents and
                        payroll.
                    </p>
                </div>

                {/* Brand & identity */}
                <Section
                    icon={<Building2 className="size-4" />}
                    title="Brand &amp; identity"
                    description="The name and logo that represent your company."
                >
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
                        <div className="flex flex-col items-center gap-2">
                            <div className="flex size-24 items-center justify-center overflow-hidden rounded-xl border border-sidebar-border/70 bg-muted/40 dark:border-sidebar-border">
                                {logoPreview ? (
                                    <img
                                        src={logoPreview}
                                        alt="Company logo"
                                        className="size-full object-contain"
                                    />
                                ) : (
                                    <span className="text-2xl font-semibold text-muted-foreground">
                                        {company.initials}
                                    </span>
                                )}
                            </div>
                            {!readOnly && (
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            fileInput.current?.click()
                                        }
                                    >
                                        <Upload className="size-3.5" />
                                        Upload
                                    </Button>
                                    {logoPreview && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground"
                                            onClick={removeLogo}
                                            aria-label="Remove logo"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                </div>
                            )}
                            <input
                                ref={fileInput}
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                className="hidden"
                                onChange={(e) =>
                                    pickLogo(e.target.files?.[0] ?? null)
                                }
                            />
                            <InputError message={errors.logo} />
                        </div>

                        <div className="grid flex-1 gap-4">
                            <Field
                                label="Display name"
                                required
                                error={errors.name}
                            >
                                <Input
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    placeholder="e.g. SYNAPSE Demo Co"
                                    disabled={readOnly}
                                    required
                                />
                            </Field>
                            <Field
                                label="Registered legal name"
                                error={errors.legal_name}
                            >
                                <Input
                                    value={data.legal_name}
                                    onChange={(e) =>
                                        setData('legal_name', e.target.value)
                                    }
                                    placeholder="e.g. Synapse Solutions, Inc."
                                    disabled={readOnly}
                                />
                            </Field>
                        </div>
                    </div>
                </Section>

                {/* Contact */}
                <Section
                    icon={<Contact className="size-4" />}
                    title="Contact details"
                    description="How the company is reached on official correspondence."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Email" error={errors.email}>
                            <Input
                                type="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                                placeholder="hr@company.com"
                                disabled={readOnly}
                            />
                        </Field>
                        <Field label="Phone" error={errors.phone}>
                            <Input
                                value={data.phone}
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                                placeholder="+63 2 1234 5678"
                                disabled={readOnly}
                            />
                        </Field>
                        <div className="sm:col-span-2">
                            <Field label="Address" error={errors.address}>
                                <textarea
                                    value={data.address}
                                    onChange={(e) =>
                                        setData('address', e.target.value)
                                    }
                                    rows={2}
                                    placeholder="Building, street, city, province, ZIP"
                                    disabled={readOnly}
                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-60"
                                />
                            </Field>
                        </div>
                    </div>
                </Section>

                {/* Statutory employer numbers */}
                <Section
                    icon={<Landmark className="size-4" />}
                    title="Government &amp; statutory"
                    description="Employer registration numbers used on payroll remittances."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="TIN" error={errors.tin}>
                            <Input
                                value={data.tin}
                                onChange={(e) => setData('tin', e.target.value)}
                                placeholder="000-000-000-000"
                                disabled={readOnly}
                            />
                        </Field>
                        <Field
                            label="SSS employer no."
                            error={errors.sss_employer_no}
                        >
                            <Input
                                value={data.sss_employer_no}
                                onChange={(e) =>
                                    setData('sss_employer_no', e.target.value)
                                }
                                placeholder="00-0000000-0"
                                disabled={readOnly}
                            />
                        </Field>
                        <Field
                            label="PhilHealth employer no."
                            error={errors.philhealth_employer_no}
                        >
                            <Input
                                value={data.philhealth_employer_no}
                                onChange={(e) =>
                                    setData(
                                        'philhealth_employer_no',
                                        e.target.value,
                                    )
                                }
                                placeholder="00-000000000-0"
                                disabled={readOnly}
                            />
                        </Field>
                        <Field
                            label="Pag-IBIG employer no."
                            error={errors.pagibig_employer_no}
                        >
                            <Input
                                value={data.pagibig_employer_no}
                                onChange={(e) =>
                                    setData(
                                        'pagibig_employer_no',
                                        e.target.value,
                                    )
                                }
                                placeholder="0000-0000-0000"
                                disabled={readOnly}
                            />
                        </Field>
                    </div>
                </Section>

                {!readOnly && (
                    <div className="flex items-center justify-end gap-3">
                        {company.updated_human && (
                            <span className="text-xs text-muted-foreground">
                                Updated {company.updated_human}
                            </span>
                        )}
                        <Button
                            type="submit"
                            disabled={processing || (!isDirty && !logoChanged)}
                        >
                            {processing && <Spinner />}
                            Save changes
                        </Button>
                    </div>
                )}
            </form>
        </>
    );
}

function Section({
    icon,
    title,
    description,
    children,
}: {
    icon: ReactNode;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm md:p-6 dark:border-sidebar-border">
            <div className="mb-4 flex items-start gap-2.5">
                <span className="flex size-7 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    {icon}
                </span>
                <div>
                    <h2 className="text-sm font-semibold">{title}</h2>
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            {children}
        </section>
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
    children: ReactNode;
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

CompanyProfilePage.layout = {
    breadcrumbs: [{ title: 'Company Profile', href: '/setup/company' }],
};

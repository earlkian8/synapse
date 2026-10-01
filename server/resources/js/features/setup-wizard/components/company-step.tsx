import { useForm } from '@inertiajs/react';
import { ChevronDown, Landmark, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { browserTimeZone, TimezoneSelect } from '@/components/timezone-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CompanyProfile } from '@/features/company-profile/types';
import { JoinCodeCard } from '@/features/employees/components/join-code-card';
import { cn } from '@/lib/utils';
import { setupWizardRoutes } from '../routes';
import type { CompanyScreen, StepControls } from '../types';
import SectionHeading from './section-heading';
import StepBody from './step-body';
import StepFooter from './step-footer';

type Props = StepControls & {
    company: CompanyProfile;
    screen: CompanyScreen;
    /** The step was saved before — keep the zone it chose rather than re-detecting. */
    savedBefore: boolean;
    logoPreview: string | null;
    onLogoPreview: (url: string | null) => void;
    onNameChange: (name: string) => void;
};

/**
 * Step 1 — the company's own identity. Same payload, validation and writer as the
 * Company Profile screen, so nothing learned here has to be re-entered there.
 *
 * Only the display name and the time zone are required. The statutory employer
 * numbers are folded away because a company registering today often does not
 * have them yet, and an empty required-looking field on the first screen of
 * setup reads as a blocker.
 *
 * The time zone is the clock attendance is judged on (ADR 0036). A company
 * saving this step for the first time starts from the zone this browser is set
 * to — the owner is usually sitting in the office — rather than a default
 * nobody chose.
 *
 * Under the profile sits the company join code (ADR 0026): the other half of a
 * company existing is people being able to find it, and the code — with the
 * switch that turns it off — is managed here exactly as on Employees → Access.
 */
export default function CompanyStep({
    company,
    screen,
    savedBefore,
    logoPreview,
    onLogoPreview,
    onNameChange,
    onNext,
    onBack,
    onSkip,
    busy,
    skipping,
}: Props) {
    const { timezones, joinCode } = screen;
    const detectedZone = savedBefore ? null : browserTimeZone(timezones);

    const { data, setData, post, processing, errors, transform } = useForm({
        name: company.name ?? '',
        legal_name: company.legal_name ?? '',
        email: company.email ?? '',
        phone: company.phone ?? '',
        address: company.address ?? '',
        timezone: detectedZone ?? company.timezone,
        tin: company.tin ?? '',
        sss_employer_no: company.sss_employer_no ?? '',
        philhealth_employer_no: company.philhealth_employer_no ?? '',
        pagibig_employer_no: company.pagibig_employer_no ?? '',
        logo: null as File | null,
        remove_logo: false,
    });

    const fileInput = useRef<HTMLInputElement>(null);
    const [showStatutory, setShowStatutory] = useState(false);

    const pickLogo = (file: File | null) => {
        if (!file) {
            return;
        }

        if (logoPreview?.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreview);
        }

        setData('logo', file);
        setData('remove_logo', false);
        onLogoPreview(URL.createObjectURL(file));
    };

    const removeLogo = () => {
        if (logoPreview?.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreview);
        }

        setData('logo', null);
        setData('remove_logo', true);
        onLogoPreview(null);

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    const submit = (event?: React.FormEvent) => {
        event?.preventDefault();

        // An empty text field means "not known yet", which is null in the
        // database rather than an empty string.
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

        post(setupWizardRoutes.company, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setData('logo', null);
                setData('remove_logo', false);
                onNext();
            },
        });
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <StepBody>
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <section className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm md:p-5 dark:border-sidebar-border">
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
                                        Logo
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
                                <input
                                    ref={fileInput}
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                    className="hidden"
                                    onChange={(event) =>
                                        pickLogo(
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                <InputError message={errors.logo} />
                            </div>

                            <div className="grid flex-1 gap-4">
                                <Field
                                    label="Display name"
                                    htmlFor="company-name"
                                    required
                                    error={errors.name}
                                    hint="What people inside the app see."
                                >
                                    <Input
                                        id="company-name"
                                        value={data.name}
                                        onChange={(event) => {
                                            setData('name', event.target.value);
                                            onNameChange(event.target.value);
                                        }}
                                        placeholder="Acme Manufacturing"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Registered legal name"
                                    htmlFor="company-legal-name"
                                    error={errors.legal_name}
                                    hint="As it appears on your SEC or DTI registration."
                                >
                                    <Input
                                        id="company-legal-name"
                                        value={data.legal_name}
                                        onChange={(event) =>
                                            setData(
                                                'legal_name',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Acme Manufacturing Corporation"
                                    />
                                </Field>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm md:p-5 dark:border-sidebar-border">
                        <h2 className="mb-4 text-sm font-semibold">
                            How the company is reached
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Email"
                                htmlFor="company-email"
                                error={errors.email}
                            >
                                <Input
                                    id="company-email"
                                    type="email"
                                    value={data.email}
                                    onChange={(event) =>
                                        setData('email', event.target.value)
                                    }
                                    placeholder="hr@acme.com.ph"
                                />
                            </Field>
                            <Field
                                label="Phone"
                                htmlFor="company-phone"
                                error={errors.phone}
                            >
                                <Input
                                    id="company-phone"
                                    value={data.phone}
                                    onChange={(event) =>
                                        setData('phone', event.target.value)
                                    }
                                    placeholder="+63 2 8123 4567"
                                />
                            </Field>
                            <div className="sm:col-span-2">
                                <Field
                                    label="Address"
                                    htmlFor="company-address"
                                    error={errors.address}
                                >
                                    <textarea
                                        id="company-address"
                                        value={data.address}
                                        onChange={(event) =>
                                            setData(
                                                'address',
                                                event.target.value,
                                            )
                                        }
                                        rows={2}
                                        placeholder="Building, street, city, province, ZIP"
                                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                                    />
                                </Field>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm md:p-5 dark:border-sidebar-border">
                        <h2 className="mb-4 text-sm font-semibold">
                            The clock the company keeps
                        </h2>
                        <Field
                            label="Time zone"
                            htmlFor="company-timezone"
                            required
                            error={errors.timezone}
                            hint={
                                detectedZone && data.timezone === detectedZone
                                    ? 'Taken from this browser. Attendance is judged on this clock — change it if the office keeps a different one.'
                                    : 'Attendance is judged on this clock: who was late, what “today” is, and which day a night shift belongs to.'
                            }
                        >
                            <TimezoneSelect
                                id="company-timezone"
                                value={data.timezone}
                                options={timezones}
                                onChange={(zone) => setData('timezone', zone)}
                                invalid={Boolean(errors.timezone)}
                            />
                        </Field>
                    </section>

                    <section className="rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                        <button
                            type="button"
                            onClick={() => setShowStatutory((open) => !open)}
                            aria-expanded={showStatutory}
                            className="flex w-full items-center gap-2.5 rounded-xl p-4 text-left md:p-5"
                        >
                            <span className="flex size-7 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <Landmark className="size-4" />
                            </span>
                            <span className="flex-1">
                                <span className="block text-sm font-semibold">
                                    Employer registration numbers
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    TIN, SSS, PhilHealth and Pag-IBIG. Add them
                                    whenever you have them — payroll reports
                                    read them from here.
                                </span>
                            </span>
                            <ChevronDown
                                className={cn(
                                    'size-4 shrink-0 text-muted-foreground transition-transform',
                                    showStatutory && 'rotate-180',
                                )}
                            />
                        </button>

                        {showStatutory && (
                            <div className="grid gap-4 px-4 pb-5 sm:grid-cols-2 md:px-5">
                                <Field
                                    label="TIN"
                                    htmlFor="company-tin"
                                    error={errors.tin}
                                >
                                    <Input
                                        id="company-tin"
                                        value={data.tin}
                                        onChange={(event) =>
                                            setData('tin', event.target.value)
                                        }
                                        placeholder="000-000-000-000"
                                    />
                                </Field>
                                <Field
                                    label="SSS employer no."
                                    htmlFor="company-sss"
                                    error={errors.sss_employer_no}
                                >
                                    <Input
                                        id="company-sss"
                                        value={data.sss_employer_no}
                                        onChange={(event) =>
                                            setData(
                                                'sss_employer_no',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="00-0000000-0"
                                    />
                                </Field>
                                <Field
                                    label="PhilHealth employer no."
                                    htmlFor="company-philhealth"
                                    error={errors.philhealth_employer_no}
                                >
                                    <Input
                                        id="company-philhealth"
                                        value={data.philhealth_employer_no}
                                        onChange={(event) =>
                                            setData(
                                                'philhealth_employer_no',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="00-000000000-0"
                                    />
                                </Field>
                                <Field
                                    label="Pag-IBIG employer no."
                                    htmlFor="company-pagibig"
                                    error={errors.pagibig_employer_no}
                                >
                                    <Input
                                        id="company-pagibig"
                                        value={data.pagibig_employer_no}
                                        onChange={(event) =>
                                            setData(
                                                'pagibig_employer_no',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="0000-0000-0000"
                                    />
                                </Field>
                            </div>
                        )}
                    </section>
                </form>

                {joinCode && (
                    <section className="flex flex-col gap-3">
                        <SectionHeading
                            title="How people join"
                            hint="Read this code out at induction or put it on a slide. You can also invite people one by one from Employees → Access."
                        />
                        <JoinCodeCard
                            code={joinCode.code}
                            enabled={joinCode.enabled}
                            canManage={screen.can.manage}
                            enabledHint="Staff enter this in the SYNAPSE app to reach your company. If their email matches an employee record they join straight away; anyone else waits for your approval under Employees → Access."
                        />
                    </section>
                )}
            </StepBody>

            <StepFooter
                onBack={onBack}
                onSkip={onSkip}
                busy={busy}
                skipping={skipping}
                primary={{
                    label: 'Save and continue',
                    onClick: () => submit(),
                    disabled: data.name.trim() === '' || data.timezone === '',
                    processing,
                }}
            />
        </div>
    );
}

function Field({
    label,
    htmlFor,
    required = false,
    error,
    hint,
    children,
}: {
    label: string;
    htmlFor: string;
    required?: boolean;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div>
            <Label htmlFor={htmlFor} className="mb-1.5 block">
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

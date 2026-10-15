import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Banknote,
    Briefcase,
    CalendarClock,
    CheckCircle2,
    Clock3,
    GraduationCap,
    MapPin,
    Users,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ApplicationForm } from '@/features/careers/components/application-form';
import { CareersShell } from '@/features/careers/components/careers-shell';
import { EMPLOYMENT_TYPE_LABELS, placeLabel } from '@/features/careers/types';
import type { CareersShowProps } from '@/features/careers/types';

export default function CareersShow({
    organization,
    posting,
    documentTypes,
    applied,
}: CareersShowProps) {
    return (
        <CareersShell organization={organization}>
            <Head title={`${posting.title} · ${organization.name}`} />

            <Link
                href={`/careers/${organization.slug}`}
                className="mb-6 inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-900"
            >
                <ArrowLeft className="size-4" />
                All open roles
            </Link>

            <div className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                <h1 className="text-2xl font-bold text-slate-900">
                    {posting.title}
                </h1>
                <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-500">
                    {posting.department && (
                        <span className="inline-flex items-center gap-1.5">
                            <Briefcase className="size-4" />
                            {posting.department}
                        </span>
                    )}
                    <span className="inline-flex items-center gap-1.5">
                        <MapPin className="size-4" />
                        {placeLabel(posting)}
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <Clock3 className="size-4" />
                        {EMPLOYMENT_TYPE_LABELS[posting.employment_type]}
                    </span>
                    {posting.min_years_experience !== null &&
                        posting.min_years_experience > 0 && (
                            <span className="inline-flex items-center gap-1.5">
                                <GraduationCap className="size-4" />
                                {posting.min_years_experience}+{' '}
                                {posting.min_years_experience === 1
                                    ? 'year'
                                    : 'years'}{' '}
                                of experience
                            </span>
                        )}
                    <span className="inline-flex items-center gap-1.5">
                        <Users className="size-4" />
                        {posting.openings}{' '}
                        {posting.openings === 1 ? 'opening' : 'openings'}
                    </span>
                    {posting.closing_date && (
                        <span className="inline-flex items-center gap-1.5">
                            <CalendarClock className="size-4" />
                            Closes {posting.closing_date}
                        </span>
                    )}
                </div>

                {posting.pay && (
                    <p className="mt-4 inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-900">
                        <Banknote className="size-4 text-[#0a9ca3]" />
                        {posting.pay}
                    </p>
                )}
                {posting.address && posting.work_arrangement !== 'remote' && (
                    <p className="mt-3 text-sm text-slate-500">
                        {posting.address}
                    </p>
                )}

                {posting.description && (
                    <Prose title="About the role" body={posting.description} />
                )}
                {posting.requirements && (
                    <Prose title="Requirements" body={posting.requirements} />
                )}
            </div>

            <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                {applied ? (
                    <ApplicationSuccess organizationSlug={organization.slug} />
                ) : (
                    <>
                        <h2 className="mb-1 text-xl font-bold text-slate-900">
                            Apply for this role
                        </h2>
                        <p className="mb-6 text-sm text-slate-500">
                            Fields marked{' '}
                            <span className="text-rose-500">*</span> are
                            required.
                        </p>
                        <ApplicationForm
                            organization={organization}
                            posting={posting}
                            documentTypes={documentTypes}
                        />
                    </>
                )}
            </div>
        </CareersShell>
    );
}

function Prose({ title, body }: { title: string; body: string }) {
    return (
        <div className="mt-6">
            <h2 className="mb-2 text-sm font-semibold tracking-wide text-slate-900 uppercase">
                {title}
            </h2>
            <p className="text-sm whitespace-pre-line text-slate-600">{body}</p>
        </div>
    );
}

function ApplicationSuccess({
    organizationSlug,
}: {
    organizationSlug: string;
}) {
    return (
        <div className="flex flex-col items-center py-8 text-center">
            <CheckCircle2 className="size-12 text-[#0ABFBF]" />
            <h2 className="mt-4 text-xl font-bold text-slate-900">
                Application received
            </h2>
            <p className="mt-2 max-w-md text-sm text-slate-500">
                Thank you for applying. The hiring team will review your
                application and reach out by email if you're a fit.
            </p>
            <Button asChild variant="outline" className="mt-6">
                <Link href={`/careers/${organizationSlug}`}>
                    Browse more roles
                </Link>
            </Button>
        </div>
    );
}

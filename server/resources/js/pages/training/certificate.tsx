import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { PageBody } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { CertificateStandingBadge } from '@/features/training/components/training-status-badge';
import { formatDate } from '@/features/training/constants';
import type { CertificatePageProps } from '@/features/training/types';

/**
 * One training certificate, laid out to print on a landscape page (ADR 0075):
 * who earned it, for which program, from whom, its number, and the dates it is
 * valid between. Printing leaves the app around it behind. HR and the holder
 * can open it.
 */
export default function TrainingCertificate() {
    const { certificate, organization, completed_on, back } =
        usePage<CertificatePageProps>().props;

    const holder = certificate.employee?.full_name ?? 'The holder';
    const issuer = certificate.issuer ?? organization.name;

    return (
        <>
            <Head title={`Certificate ${certificate.certificate_no ?? ''}`} />

            <PageBody className="items-center">
                <div className="certificate-no-print flex w-full max-w-4xl flex-wrap items-center justify-between gap-3">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={back}>
                            <ArrowLeft className="size-4" />
                            Back
                        </Link>
                    </Button>
                    <div className="flex items-center gap-3">
                        <CertificateStandingBadge
                            standing={certificate.standing}
                        />
                        <Button size="sm" onClick={() => window.print()}>
                            <Printer className="size-4" />
                            Print or save as PDF
                        </Button>
                    </div>
                </div>

                <article
                    id="certificate-print"
                    aria-label={`Certificate of completion for ${holder}`}
                    className="w-full max-w-4xl rounded-2xl bg-white p-3 text-[#0F2044] shadow-sm ring-1 ring-sidebar-border/70 sm:p-4 dark:ring-sidebar-border"
                >
                    <div className="flex aspect-auto flex-col items-center gap-6 rounded-xl border-2 border-[#0F2044] px-6 py-10 text-center sm:aspect-[1.414] sm:justify-between sm:px-14 sm:py-12">
                        <header className="flex flex-col items-center gap-3">
                            {organization.logo ? (
                                <img
                                    src={organization.logo}
                                    alt=""
                                    className="h-12 w-auto object-contain"
                                />
                            ) : null}
                            <p className="text-sm font-medium tracking-wide text-[#0F2044]/70">
                                {organization.name}
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight sm:text-4xl">
                                Certificate of Completion
                            </h1>
                            <span
                                aria-hidden
                                className="h-0.5 w-16 rounded-full bg-[#0ABFBF]"
                            />
                        </header>

                        <div className="flex flex-col items-center gap-3">
                            <p className="text-sm text-[#0F2044]/70">
                                This certifies that
                            </p>
                            <p className="text-3xl font-semibold tracking-tight sm:text-5xl">
                                {holder}
                            </p>
                            <p className="max-w-xl text-sm text-[#0F2044]/70 sm:text-base">
                                has completed{' '}
                                <span className="font-semibold text-[#0F2044]">
                                    {certificate.name}
                                </span>
                                {issuer && <>, given by {issuer}</>}
                                {completed_on && (
                                    <>, on {formatDate(completed_on)}</>
                                )}
                                .
                            </p>
                        </div>

                        <footer className="grid w-full gap-6 text-left text-xs sm:grid-cols-3 sm:items-end">
                            <dl className="flex flex-col gap-1">
                                <dt className="text-[#0F2044]/60">
                                    Certificate no.
                                </dt>
                                <dd className="font-semibold tabular-nums">
                                    {certificate.certificate_no}
                                </dd>
                            </dl>
                            <dl className="flex flex-col gap-1 sm:text-center">
                                <dt className="text-[#0F2044]/60">Issued</dt>
                                <dd className="font-semibold">
                                    {formatDate(certificate.issued_date)}
                                </dd>
                            </dl>
                            <dl className="flex flex-col gap-1 sm:text-right">
                                <dt className="text-[#0F2044]/60">
                                    Valid until
                                </dt>
                                <dd className="font-semibold">
                                    {certificate.expiry_date
                                        ? formatDate(certificate.expiry_date)
                                        : 'No expiry'}
                                </dd>
                            </dl>
                        </footer>
                    </div>
                </article>
            </PageBody>

            <style>{`
                @media print {
                    @page { size: A4 landscape; margin: 10mm; }
                    body * { visibility: hidden; }
                    #certificate-print, #certificate-print * { visibility: visible; }
                    #certificate-print { position: absolute; left: 0; top: 0; width: 100%; max-width: none; box-shadow: none; }
                    .certificate-no-print { display: none !important; }
                }
            `}</style>
        </>
    );
}

TrainingCertificate.layout = (props: CertificatePageProps) => ({
    breadcrumbs: [
        { title: 'Training & Development', href: '/training' },
        {
            title: props.back.startsWith('/training/me')
                ? 'My training'
                : 'Certificates',
            href: props.back,
        },
        {
            title: props.certificate.certificate_no ?? 'Certificate',
            href: `/training/certificates/${props.certificate.id}`,
        },
    ],
});

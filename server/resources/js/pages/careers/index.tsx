import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Briefcase, CalendarClock, MapPin } from 'lucide-react';
import { CareersShell } from '@/features/careers/components/careers-shell';
import { EMPLOYMENT_TYPE_LABELS } from '@/features/careers/types';
import type { CareersBoardProps, PostingCard } from '@/features/careers/types';

export default function CareersIndex({
    organization,
    postings,
}: CareersBoardProps) {
    return (
        <CareersShell organization={organization}>
            <Head title={`Careers · ${organization.name}`} />

            <div className="mb-8">
                <h1 className="text-2xl font-bold text-slate-900">
                    Open roles at {organization.name}
                </h1>
                <p className="mt-1 text-sm text-slate-500">
                    {postings.length === 0
                        ? 'No open roles right now — check back soon.'
                        : `${postings.length} open ${postings.length === 1 ? 'role' : 'roles'}. Find your fit and apply.`}
                </p>
            </div>

            {postings.length > 0 && (
                <ul className="space-y-3">
                    {postings.map((posting) => (
                        <li key={posting.hashid}>
                            <PostingRow
                                organizationSlug={organization.slug}
                                posting={posting}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </CareersShell>
    );
}

function PostingRow({
    organizationSlug,
    posting,
}: {
    organizationSlug: string;
    posting: PostingCard;
}) {
    return (
        <Link
            href={`/careers/${organizationSlug}/jobs/${posting.hashid}`}
            className="group flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5 transition-colors hover:border-[#0ABFBF]"
        >
            <div className="min-w-0">
                <h2 className="font-semibold text-slate-900">
                    {posting.title}
                </h2>
                <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                    {posting.department && (
                        <span className="inline-flex items-center gap-1">
                            <Briefcase className="size-3.5" />
                            {posting.department}
                        </span>
                    )}
                    <span className="inline-flex items-center gap-1">
                        <MapPin className="size-3.5" />
                        {EMPLOYMENT_TYPE_LABELS[posting.employment_type]}
                    </span>
                    {posting.closing_date && (
                        <span className="inline-flex items-center gap-1">
                            <CalendarClock className="size-3.5" />
                            Closes {posting.closing_date}
                        </span>
                    )}
                </div>
                {posting.excerpt && (
                    <p className="mt-2 line-clamp-2 text-sm text-slate-600">
                        {posting.excerpt}
                    </p>
                )}
            </div>
            <ArrowRight className="size-5 shrink-0 text-slate-300 transition-colors group-hover:text-[#0ABFBF]" />
        </Link>
    );
}

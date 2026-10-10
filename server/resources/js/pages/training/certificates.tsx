import { Head, Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    CalendarClock,
    Download,
    Eye,
    History,
    RefreshCw,
    ShieldAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    RowMenuTrigger,
    rowOpens,
    SearchInput,
    SortableHead,
    StatTiles,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { enrollEmployees } from '@/features/training/api';
import { TrainingNav } from '@/features/training/components/training-nav';
import { CertificateStandingBadge } from '@/features/training/components/training-status-badge';
import {
    formatDate,
    PROGRAM_STATUS_LABELS,
    STANDING_LABELS,
} from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type {
    CertificatesPageProps,
    CertificateStanding,
    TrainingCertificate,
} from '@/features/training/types';

type Sort = 'name' | 'certificate' | 'issued' | 'expires';

/** Needing attention first: expired, then expiring, then the rest. */
const STANDING_RANK: Record<CertificateStanding, number> = {
    expired: 0,
    expiring: 1,
    valid: 2,
    renewed: 3,
};

/**
 * The certificate register (ADR 0075): every certificate training has issued,
 * with its standing today — valid, expiring within the warning window,
 * expired, or renewed by a later one. HR renews a certificate by enrolling its
 * holder in a program that is still running.
 */
export default function TrainingCertificates() {
    const {
        certificates,
        stats,
        expiring_days,
        programs,
        standing: initialStanding,
        can,
        nav,
    } = usePage<CertificatesPageProps>().props;

    const [search, setSearch] = useState('');
    const [standing, setStanding] = useState<CertificateStanding | 'all'>(
        initialStanding ?? 'all',
    );
    const [sort, setSort] = useState<Sort>('expires');
    const [direction, setDirection] = useState<'asc' | 'desc'>('asc');
    const [renewing, setRenewing] = useState<TrainingCertificate | null>(null);

    const onSort = (key: Sort) => {
        if (key === sort) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(key);
            setDirection(key === 'issued' ? 'desc' : 'asc');
        }
    };

    const sortable = (key: Sort) => ({
        active: sort === key,
        direction,
        onSort: () => onSort(key),
    });

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();
        const dir = direction === 'asc' ? 1 : -1;

        return certificates
            .filter(
                (c) =>
                    (standing === 'all' || c.standing === standing) &&
                    (needle === '' ||
                        c.name.toLowerCase().includes(needle) ||
                        (c.certificate_no ?? '')
                            .toLowerCase()
                            .includes(needle) ||
                        (c.employee?.full_name ?? '')
                            .toLowerCase()
                            .includes(needle)),
            )
            .sort((a, b) => {
                switch (sort) {
                    case 'name':
                        return (
                            (a.employee?.full_name ?? '').localeCompare(
                                b.employee?.full_name ?? '',
                            ) * dir
                        );
                    case 'certificate':
                        return a.name.localeCompare(b.name) * dir;
                    case 'issued':
                        return (
                            (a.issued_date ?? '').localeCompare(
                                b.issued_date ?? '',
                            ) * dir
                        );
                    default:
                        return (
                            (STANDING_RANK[a.standing] -
                                STANDING_RANK[b.standing]) *
                                dir ||
                            (a.expiry_date ?? '9999').localeCompare(
                                b.expiry_date ?? '9999',
                            ) * dir
                        );
                }
            });
    }, [certificates, search, standing, sort, direction]);

    const page = useClientPagination(
        rows,
        [search, standing, sort, direction].join('|'),
    );
    const isFiltered = search !== '' || standing !== 'all';

    return (
        <>
            <Head title="Training certificates" />

            <PageBody>
                <PageHeader
                    title="Certificates"
                    description="Every certificate training has issued, and when each expires."
                    actions={
                        <TrainingNav current="certificates" counts={nav} />
                    }
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'valid',
                            label: 'Valid',
                            value: stats.valid.toLocaleString(),
                            icon: BadgeCheck,
                            accent: 'emerald',
                        },
                        {
                            key: 'expiring',
                            label: `Expiring in ${expiring_days} days`,
                            value: stats.expiring.toLocaleString(),
                            icon: CalendarClock,
                            accent: 'amber',
                            valueClassName:
                                stats.expiring > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : undefined,
                        },
                        {
                            key: 'expired',
                            label: 'Expired',
                            value: stats.expired.toLocaleString(),
                            icon: ShieldAlert,
                            accent: 'rose',
                            valueClassName:
                                stats.expired > 0
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : undefined,
                        },
                        {
                            key: 'renewed',
                            label: 'Renewed',
                            value: stats.renewed.toLocaleString(),
                            hint: 'by a later one',
                            icon: History,
                            accent: 'slate',
                        },
                    ]}
                />

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={isFiltered}
                        onReset={() => {
                            setSearch('');
                            setStanding('all');
                        }}
                        actions={
                            certificates.length > 0 && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href={trainingRoutes.certificatesExport}>
                                        <Download className="size-4" />
                                        Export
                                    </a>
                                </Button>
                            )
                        }
                    >
                        <SearchInput
                            value={search}
                            onSearch={setSearch}
                            delay={0}
                            placeholder="Search person, certificate or number…"
                            label="Search certificates"
                        />
                        <FilterSelect
                            label="Filter by standing"
                            value={standing}
                            onChange={(value) =>
                                setStanding(
                                    value as CertificateStanding | 'all',
                                )
                            }
                            options={[
                                { value: 'all', label: 'Any standing' },
                                ...(
                                    Object.keys(
                                        STANDING_LABELS,
                                    ) as CertificateStanding[]
                                ).map((s) => ({
                                    value: s,
                                    label: STANDING_LABELS[s],
                                })),
                            ]}
                            className="w-44"
                        />
                    </ListToolbar>

                    <TableCard>
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead {...sortable('name')}>
                                        Employee
                                    </SortableHead>
                                    <SortableHead {...sortable('certificate')}>
                                        Certificate
                                    </SortableHead>
                                    <SortableHead
                                        {...sortable('issued')}
                                        className="hidden md:table-cell"
                                    >
                                        Issued
                                    </SortableHead>
                                    <SortableHead
                                        {...sortable('expires')}
                                        className="hidden sm:table-cell"
                                    >
                                        Expires
                                    </SortableHead>
                                    <TableHead>Standing</TableHead>
                                    <TableHead className="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {page.rows.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={6}
                                        icon={BadgeCheck}
                                        title={
                                            certificates.length === 0
                                                ? 'No certificates issued yet'
                                                : 'No certificate matches'
                                        }
                                        description={
                                            certificates.length === 0
                                                ? 'Turn on “Completing it earns a certificate” on a program; marking people completed issues theirs.'
                                                : 'Try another standing, or clear the search.'
                                        }
                                    />
                                )}
                                {page.rows.map((c) => {
                                    const name =
                                        c.employee?.full_name ?? 'Unknown';
                                    const canRenew =
                                        can.manage &&
                                        (c.standing === 'expiring' ||
                                            c.standing === 'expired');

                                    return (
                                        <TableRow
                                            key={c.id}
                                            {...rowOpens(
                                                trainingRoutes.certificate(
                                                    c.id,
                                                ),
                                            )}
                                        >
                                            <TableCell>
                                                <div className="flex min-w-0 items-center gap-2.5">
                                                    <PersonAvatar
                                                        name={name}
                                                        initials={
                                                            c.employee
                                                                ?.initials ??
                                                            '?'
                                                        }
                                                        photo={
                                                            c.employee?.photo
                                                        }
                                                        className="size-8"
                                                    />
                                                    <div className="min-w-0">
                                                        <p className="max-w-52 truncate text-sm font-medium">
                                                            {name}
                                                        </p>
                                                        <p className="max-w-52 truncate text-xs text-muted-foreground">
                                                            {c.employee
                                                                ?.department ??
                                                                c.employee
                                                                    ?.employee_no}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <p className="max-w-60 truncate text-sm">
                                                    {c.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground tabular-nums">
                                                    {c.certificate_no}
                                                </p>
                                            </TableCell>
                                            <TableCell className="hidden text-sm text-muted-foreground md:table-cell">
                                                {formatDate(c.issued_date)}
                                            </TableCell>
                                            <TableCell className="hidden text-sm sm:table-cell">
                                                {c.expiry_date
                                                    ? formatDate(c.expiry_date)
                                                    : 'No expiry'}
                                            </TableCell>
                                            <TableCell>
                                                <CertificateStandingBadge
                                                    standing={c.standing}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <RowMenuTrigger
                                                            label={`Actions for ${c.name}, ${name}`}
                                                        />
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent
                                                        align="end"
                                                        className="w-52"
                                                    >
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={trainingRoutes.certificate(
                                                                    c.id,
                                                                )}
                                                            >
                                                                <Eye className="size-4" />
                                                                View certificate
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        {canRenew && (
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    setRenewing(
                                                                        c,
                                                                    )
                                                                }
                                                            >
                                                                <RefreshCw className="size-4" />
                                                                Renew by
                                                                enrolling again
                                                            </DropdownMenuItem>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </DataTable>
                    </TableCard>

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

            <RenewModal
                certificate={renewing}
                programs={programs}
                onOpenChange={(open) => !open && setRenewing(null)}
            />
        </>
    );
}

/** Renew a certificate by enrolling its holder in a program still running. */
function RenewModal({
    certificate,
    programs,
    onOpenChange,
}: {
    certificate: TrainingCertificate | null;
    programs: CertificatesPageProps['programs'];
    onOpenChange: (open: boolean) => void;
}) {
    const [choice, setChoice] = useState('');
    const [processing, setProcessing] = useState(false);

    // Programs earning a certificate first; one with the same name preferred.
    const options = useMemo(
        () =>
            [...programs]
                .sort(
                    (a, b) =>
                        Number(b.name === certificate?.name) -
                            Number(a.name === certificate?.name) ||
                        Number(b.issues_certificate) -
                            Number(a.issues_certificate),
                )
                .map((p) => ({
                    value: p.hashid,
                    label: `${p.name} · ${PROGRAM_STATUS_LABELS[p.status]}${p.issues_certificate ? '' : ' (no certificate)'}`,
                })),
        [programs, certificate],
    );
    const selected = choice || options[0]?.value || '';

    const submit = () => {
        if (!certificate?.employee || !selected) {
            return;
        }

        enrollEmployees(selected, [certificate.employee.id], {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                setChoice('');
                onOpenChange(false);
            },
        });
    };

    return (
        <Modal open={certificate !== null} onOpenChange={onOpenChange}>
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <RefreshCw />
                        </ModalIcon>
                    }
                    title="Renew the certificate"
                    description={`Enroll ${certificate?.employee?.full_name ?? 'them'} in a program; completing it issues a new certificate.`}
                />
                <ModalBody>
                    {options.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No program is running or coming up. Create one on
                            Programs first.
                        </p>
                    ) : (
                        <FormField label="Program">
                            <FormSelect
                                value={selected}
                                onChange={setChoice}
                                options={options}
                            />
                        </FormField>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={processing || options.length === 0}
                    >
                        {processing && <Spinner />}
                        Enroll
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}

TrainingCertificates.layout = {
    breadcrumbs: [
        { title: 'Training & Development', href: '/training' },
        { title: 'Certificates', href: '/training/certificates' },
    ],
};

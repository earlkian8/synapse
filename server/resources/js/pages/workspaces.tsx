import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronRight, LogOut, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

/**
 * The post-login workspace picker — the "pick a company" landing for identities
 * that belong to more than one organisation (ADR 0023). Choosing a workspace posts
 * to `organization.switch`, which binds the tenant and forwards to the dashboard.
 *
 * It carries its own full-screen chrome (no app shell): SYNAPSE's deep-navy field
 * with a faint neural node backdrop — the workspaces themselves are the nodes you
 * connect into. Companies read as rounded squares here, the system-wide mark for an
 * organisation (people are always circles).
 */

type Workspace = {
    id: number;
    name: string;
    logo_url: string | null;
    initials: string;
    role: string | null;
    people_count: number;
    is_default: boolean;
    is_active: boolean;
};

export default function Workspaces({
    workspaces,
}: {
    workspaces: Workspace[];
}) {
    const { auth } = usePage().props;
    const [query, setQuery] = useState('');
    const [entering, setEntering] = useState<number | null>(null);

    const showSearch = workspaces.length > 6;

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return workspaces;
        }

        return workspaces.filter((workspace) =>
            workspace.name.toLowerCase().includes(needle),
        );
    }, [query, workspaces]);

    const enter = (id: number) => {
        if (entering !== null) {
            return;
        }

        setEntering(id);

        router.post(
            '/organization/switch',
            { organization_id: id },
            {
                preserveScroll: false,
                onError: () => setEntering(null),
            },
        );
    };

    return (
        <>
            <Head title="Choose a workspace" />

            <div className="relative flex min-h-dvh flex-col overflow-hidden bg-[#0F2044] text-white">
                <SynapseField />

                {/* Top brand bar */}
                <header className="relative z-10 flex items-center justify-between px-6 py-5 sm:px-10">
                    <div className="flex items-center gap-2">
                        <AppLogoIcon className="size-7 object-contain" />
                        <span className="text-sm font-bold tracking-[0.2em] text-white/90">
                            SYNAPSE
                        </span>
                    </div>
                    <Link
                        href={logout()}
                        as="button"
                        className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-white/55 transition-colors hover:text-white"
                    >
                        <LogOut className="size-3.5" />
                        Sign out
                    </Link>
                </header>

                {/* Picker */}
                <main className="relative z-10 mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-6 py-10 sm:px-0">
                    <div className="mb-8">
                        <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-3 py-1 text-[11px] font-medium tracking-wider text-[#0ABFBF] uppercase">
                            <span className="size-1.5 rounded-full bg-[#0ABFBF]" />
                            {workspaces.length} workspaces
                        </div>
                        <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                            Choose a workspace
                        </h1>
                        <p className="mt-2 text-sm text-white/55">
                            Pick the company you'd like to work in. You can
                            switch anytime from the sidebar.
                        </p>
                    </div>

                    {showSearch && (
                        <div className="relative mb-4">
                            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-white/35" />
                            <input
                                type="text"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search companies"
                                aria-label="Search companies"
                                className="w-full rounded-xl border border-white/10 bg-white/5 py-2.5 pr-4 pl-10 text-sm text-white placeholder:text-white/35 focus:border-[#0ABFBF]/50 focus:ring-2 focus:ring-[#0ABFBF]/30 focus:outline-none"
                            />
                        </div>
                    )}

                    <ul className="flex flex-col gap-2.5">
                        {filtered.map((workspace, index) => (
                            <li
                                key={workspace.id}
                                className="workspace-rise"
                                style={{
                                    animationDelay: `${Math.min(index, 8) * 55}ms`,
                                }}
                            >
                                <WorkspaceCard
                                    workspace={workspace}
                                    busy={entering === workspace.id}
                                    disabled={
                                        entering !== null &&
                                        entering !== workspace.id
                                    }
                                    onSelect={() => enter(workspace.id)}
                                />
                            </li>
                        ))}

                        {filtered.length === 0 && (
                            <li className="rounded-2xl border border-dashed border-white/15 px-6 py-10 text-center text-sm text-white/45">
                                No companies match “{query}”.
                            </li>
                        )}
                    </ul>

                    <p className="mt-8 text-center text-xs text-white/35">
                        Signed in as{' '}
                        <span className="text-white/60">{auth.user.email}</span>
                    </p>
                </main>
            </div>

            <PickerStyles />
        </>
    );
}

/** A single selectable company. Rounded-square mark, role + headcount, an enter affordance. */
function WorkspaceCard({
    workspace,
    busy,
    disabled,
    onSelect,
}: {
    workspace: Workspace;
    busy: boolean;
    disabled: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onSelect}
            disabled={disabled || busy}
            aria-label={`Enter ${workspace.name}`}
            className={`group relative flex w-full items-center gap-4 rounded-2xl border bg-white/[0.04] px-4 py-3.5 text-left backdrop-blur-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-[#0ABFBF]/60 hover:bg-white/[0.07] focus-visible:border-[#0ABFBF] focus-visible:ring-2 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none disabled:cursor-default disabled:opacity-55 disabled:hover:translate-y-0 ${
                workspace.is_active
                    ? 'border-[#0ABFBF]/40 ring-1 ring-[#0ABFBF]/30'
                    : 'border-white/10'
            }`}
        >
            <CompanyMark workspace={workspace} />

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate text-[15px] font-semibold text-white">
                        {workspace.name}
                    </span>
                    {workspace.is_default && (
                        <span className="rounded-full bg-[#0ABFBF]/15 px-2 py-0.5 text-[10px] font-medium tracking-wide text-[#0ABFBF] uppercase">
                            Default
                        </span>
                    )}
                </div>
                <p className="mt-0.5 truncate text-xs text-white/50">
                    {workspace.role ?? 'Member'}
                    <span className="px-1.5 text-white/25">·</span>
                    {workspace.people_count.toLocaleString()}{' '}
                    {workspace.people_count === 1 ? 'person' : 'people'}
                </p>
            </div>

            {/* Enter affordance — a spinner while entering, otherwise a chevron that
                slides on hover and reveals an "Enter" hint. */}
            <div className="flex shrink-0 items-center gap-2">
                {busy ? (
                    <span className="flex items-center gap-2 text-xs font-medium text-[#0ABFBF]">
                        <Spinner className="size-4" />
                        Entering
                    </span>
                ) : (
                    <>
                        <span className="text-xs font-medium text-[#0ABFBF] opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                            Enter
                        </span>
                        <ChevronRight className="size-5 text-white/35 transition-all duration-200 group-hover:translate-x-0.5 group-hover:text-[#0ABFBF]" />
                    </>
                )}
            </div>
        </button>
    );
}

/** A company's rounded-square logo, with an initials fallback and an active tick. */
function CompanyMark({ workspace }: { workspace: Workspace }) {
    return (
        <div className="relative">
            <div className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white/10 ring-1 ring-white/10">
                {workspace.logo_url ? (
                    <img
                        src={workspace.logo_url}
                        alt=""
                        className="size-full object-contain"
                    />
                ) : workspace.initials ? (
                    <span className="text-sm font-semibold tracking-wide text-white/80 uppercase">
                        {workspace.initials}
                    </span>
                ) : (
                    <Building2 className="size-5 text-white/60" />
                )}
            </div>
            {workspace.is_active && (
                <span className="absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full bg-[#0ABFBF] ring-2 ring-[#0F2044]">
                    <Check
                        className="size-2.5 text-[#0F2044]"
                        strokeWidth={3}
                    />
                </span>
            )}
        </div>
    );
}

/**
 * The signature backdrop: a faint network of nodes and links — a "synapse" the
 * workspaces plug into. Static and very low contrast so it never competes with the
 * cards; the slow pulse is dropped under reduced motion.
 */
function SynapseField() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 overflow-hidden"
        >
            {/* Depth: a teal glow up top, a darker vignette toward the base. */}
            <div className="absolute -top-40 left-1/2 size-[640px] -translate-x-1/2 rounded-full bg-[#0ABFBF]/10 blur-[120px]" />
            <div className="absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-[#091632]" />

            <svg
                className="absolute inset-0 size-full opacity-[0.5]"
                viewBox="0 0 800 600"
                preserveAspectRatio="xMidYMid slice"
                fill="none"
            >
                <g stroke="#0ABFBF" strokeWidth="0.6" strokeOpacity="0.25">
                    <line x1="90" y1="110" x2="250" y2="180" />
                    <line x1="250" y1="180" x2="180" y2="360" />
                    <line x1="250" y1="180" x2="430" y2="120" />
                    <line x1="430" y1="120" x2="610" y2="200" />
                    <line x1="610" y1="200" x2="540" y2="400" />
                    <line x1="180" y1="360" x2="380" y2="470" />
                    <line x1="380" y1="470" x2="540" y2="400" />
                    <line x1="430" y1="120" x2="380" y2="470" />
                    <line x1="700" y1="90" x2="610" y2="200" />
                    <line x1="120" y1="520" x2="180" y2="360" />
                </g>
                <g fill="#0ABFBF">
                    {[
                        [90, 110],
                        [250, 180],
                        [430, 120],
                        [610, 200],
                        [180, 360],
                        [540, 400],
                        [380, 470],
                        [700, 90],
                        [120, 520],
                    ].map(([cx, cy], index) => (
                        <circle
                            key={`${cx}-${cy}`}
                            cx={cx}
                            cy={cy}
                            r={index % 3 === 0 ? 3 : 2}
                            className="synapse-node"
                            style={{ animationDelay: `${index * 320}ms` }}
                        />
                    ))}
                </g>
            </svg>
        </div>
    );
}

/** Self-contained keyframes for the picker — card entrance + node pulse, both
 *  disabled when the user prefers reduced motion. */
function PickerStyles() {
    return (
        <style>{`
            @keyframes workspace-rise {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .workspace-rise {
                opacity: 0;
                animation: workspace-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            }
            @keyframes synapse-node {
                0%, 100% { opacity: 0.3; }
                50% { opacity: 0.85; }
            }
            .synapse-node {
                animation: synapse-node 4.5s ease-in-out infinite;
            }
            @media (prefers-reduced-motion: reduce) {
                .workspace-rise { opacity: 1; animation: none; }
                .synapse-node { animation: none; }
            }
        `}</style>
    );
}

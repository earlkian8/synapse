import { Head, Link, usePage } from '@inertiajs/react';
import { Check, Copy, Link2Off } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';

type InvitePageProps = {
    invitation: {
        code: string;
        email: string;
        expires_human: string | null;
        organization: {
            name: string | null;
            logo: string | null;
            initials: string | null;
        };
        employee: {
            first_name: string | null;
            position: string | null;
            department: string | null;
        };
    } | null;
};

/**
 * Where the invitation email's link lands (ADR 0026).
 *
 * The page has exactly one job, and it is not the one a "click here to accept"
 * link implies: the account this invitation is for does not exist yet, and it gets
 * created in the mobile app. So the code — not a button — is the hero, sized to be
 * read off a laptop while typing it into a phone. Everything else on the page
 * exists to prove the invitation is genuine before somebody acts on it.
 */
export default function Invite() {
    const { invitation } = usePage<InvitePageProps>().props;
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!invitation) {
            return;
        }

        try {
            await navigator.clipboard.writeText(invitation.code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard unavailable — the code is on screen at 40px.
        }
    };

    return (
        <>
            <Head title={invitation ? 'Your invitation' : 'Invitation'} />

            <div className="flex min-h-screen flex-col items-center justify-center bg-[#0F2044] px-6 py-12 text-white">
                <Link
                    href="/"
                    className="mb-10 flex items-center gap-2 text-sm font-bold tracking-widest text-white/70 transition-colors hover:text-white"
                >
                    <AppLogoIcon className="size-7 object-contain" />
                    SYNAPSE
                </Link>

                {invitation === null ? (
                    <div className="w-full max-w-md rounded-2xl bg-white/5 p-8 text-center ring-1 ring-white/10">
                        <span className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-white/10">
                            <Link2Off className="size-6 text-white/70" />
                        </span>
                        <h1 className="text-lg font-semibold">
                            This invitation has expired
                        </h1>
                        <p className="mt-2 text-sm leading-relaxed text-white/60">
                            Invitation links stop working after two weeks, or
                            once a newer one has been sent. Ask your HR team to
                            send you another.
                        </p>
                    </div>
                ) : (
                    <div className="w-full max-w-md">
                        <div className="rounded-2xl bg-white p-8 text-[#0F2044] shadow-2xl">
                            <div className="flex items-center gap-3">
                                {invitation.organization.logo ? (
                                    <img
                                        src={invitation.organization.logo}
                                        alt=""
                                        className="size-11 rounded-xl object-cover"
                                    />
                                ) : (
                                    <span className="flex size-11 items-center justify-center rounded-xl bg-[#0F2044] text-sm font-semibold text-white">
                                        {invitation.organization.initials}
                                    </span>
                                )}
                                <div className="min-w-0">
                                    <p className="truncate text-base font-semibold">
                                        {invitation.organization.name}
                                    </p>
                                    <p className="truncate text-sm text-slate-500">
                                        {[
                                            invitation.employee.position,
                                            invitation.employee.department,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || 'Employee'}
                                    </p>
                                </div>
                            </div>

                            <h1 className="mt-6 text-xl leading-snug font-semibold">
                                {invitation.employee.first_name
                                    ? `${invitation.employee.first_name}, you've been invited`
                                    : "You've been invited"}
                            </h1>
                            <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                Install SYNAPSE on your phone and create an
                                account — any email address you like — then
                                enter this code to connect to{' '}
                                {invitation.organization.name}.
                            </p>

                            <div className="mt-6 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-center">
                                <span className="text-xs font-medium tracking-wide text-slate-500 uppercase">
                                    Your invitation code
                                </span>
                                <div className="mt-2 flex items-center justify-center gap-2">
                                    <span className="font-mono text-3xl font-semibold tracking-[0.22em] tabular-nums sm:text-4xl">
                                        {invitation.code}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={copy}
                                        aria-label={
                                            copied
                                                ? 'Code copied'
                                                : 'Copy invitation code'
                                        }
                                        className="rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-200 hover:text-slate-700 focus-visible:ring-2 focus-visible:ring-[#0ABFBF] focus-visible:outline-none"
                                    >
                                        {copied ? (
                                            <Check className="size-4 text-[#0ABFBF]" />
                                        ) : (
                                            <Copy className="size-4" />
                                        )}
                                    </button>
                                </div>
                                {invitation.expires_human && (
                                    <p className="mt-3 text-xs text-slate-500">
                                        Expires {invitation.expires_human}
                                    </p>
                                )}
                            </div>

                            <p className="mt-5 text-xs leading-relaxed text-slate-500">
                                This invitation was sent to {invitation.email}.
                                You don't have to sign up with that address —
                                the code is what connects you.
                            </p>
                        </div>

                        <p className="mt-6 text-center text-sm text-white/50">
                            Weren't expecting this? You can ignore it — nothing
                            happens until the code is used.
                        </p>
                    </div>
                )}
            </div>
        </>
    );
}

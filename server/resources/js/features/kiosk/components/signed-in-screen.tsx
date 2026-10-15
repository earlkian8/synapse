import { LogOut } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Spinner } from '@/components/ui/spinner';
import { kioskRoutes } from '../routes';

/**
 * A browser somebody is signed in on (ADR 0092). Its pages carry their name and
 * their access, and anyone at a kiosk could walk into their account — so the
 * kiosk asks them to sign out first, and comes back once they have.
 */
export function SignedInScreen() {
    const [busy, setBusy] = useState(false);

    const signOut = async () => {
        setBusy(true);
        const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

        await fetch('/logout', {
            method: 'POST',
            headers: {
                Accept: 'text/html',
                'X-XSRF-TOKEN': token ? decodeURIComponent(token[1]) : '',
            },
            credentials: 'same-origin',
        }).catch(() => null);

        window.location.assign(kioskRoutes.show);
    };

    return (
        <div className="w-full max-w-xl">
            <AppLogoIcon surface="dark" className="mb-10 h-7 w-auto" />
            <h1 className="text-[clamp(1.9rem,4vw,2.6rem)] leading-tight font-semibold tracking-tight">
                Sign out to use this browser as a kiosk
            </h1>
            <p className="mt-3 max-w-[48ch] text-lg text-white/70">
                Everyone uses a kiosk, so nobody can be signed in to Synapse on
                it. Signing out keeps your account out of reach of whoever
                stands here next.
            </p>
            <button
                type="button"
                onClick={() => void signOut()}
                disabled={busy}
                className="mt-10 inline-flex h-14 items-center gap-2 rounded-2xl bg-white px-6 text-lg font-semibold text-(--k-ink) focus-visible:ring-4 focus-visible:ring-(--k-signal)/50 focus-visible:outline-none disabled:opacity-60"
            >
                {busy ? <Spinner /> : <LogOut className="size-5" />}
                Sign out
            </button>
        </div>
    );
}

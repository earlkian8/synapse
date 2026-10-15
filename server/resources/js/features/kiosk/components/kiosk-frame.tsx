import { Maximize, Minimize } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { KIOSK_TOKENS } from '../constants';
import type { KioskInfo } from '../types';

/**
 * The kiosk's chrome: whose kiosk it is and where, a way into full screen for
 * whoever sets the tablet up, and the two halves of the screen — the clock, and
 * the card a person works on. Side by side on a landscape tablet; stacked on a
 * portrait one or a phone.
 */
export function KioskFrame({
    kiosk,
    clock,
    children,
}: {
    kiosk: KioskInfo | null;
    clock?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div
            style={KIOSK_TOKENS}
            className="flex min-h-dvh flex-col bg-(--k-ground) text-white antialiased selection:bg-(--k-signal)/30"
        >
            <header className="flex items-center gap-3 px-5 pt-5 sm:px-10 sm:pt-7">
                {kiosk && <Company kiosk={kiosk} />}
                <FullscreenButton />
            </header>

            {clock ? (
                <main className="grid flex-1 content-center gap-10 px-5 py-6 sm:px-10 sm:py-8 lg:gap-16 md:landscape:grid-cols-[minmax(0,1fr)_minmax(24rem,30rem)] md:landscape:content-normal md:landscape:items-center md:landscape:gap-12">
                    {/* Upright, the clock and the card share one column. */}
                    <div className="mx-auto w-full max-w-[30rem] md:landscape:mx-0 md:landscape:max-w-none">
                        {clock}
                    </div>
                    <div className="mx-auto w-full max-w-[30rem]">
                        {children}
                    </div>
                </main>
            ) : (
                <main className="flex flex-1 items-center justify-center px-5 py-10">
                    {children}
                </main>
            )}
        </div>
    );
}

function Company({ kiosk }: { kiosk: KioskInfo }) {
    const { organization } = kiosk;

    return (
        <div className="flex min-w-0 items-center gap-3">
            {organization.logo_url ? (
                <img
                    src={organization.logo_url}
                    alt=""
                    className="size-10 shrink-0 rounded-lg bg-white object-contain p-1"
                />
            ) : (
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-(--k-ground-raised) text-sm font-semibold text-(--k-signal)">
                    {organization.initials}
                </span>
            )}
            <div className="min-w-0">
                <p className="truncate text-sm font-semibold">
                    {organization.name}
                </p>
                <p className="truncate text-sm text-white/60">
                    {kiosk.site ? `${kiosk.name}, ${kiosk.site}` : kiosk.name}
                </p>
            </div>
        </div>
    );
}

/**
 * Full screen hides the browser's bars, so the kiosk is all a passer-by sees.
 * Offered only where the browser can do it.
 */
function FullscreenButton() {
    const [full, setFull] = useState(false);
    const can =
        typeof document !== 'undefined' && document.fullscreenEnabled === true;

    useEffect(() => {
        const sync = () => setFull(document.fullscreenElement !== null);
        document.addEventListener('fullscreenchange', sync);

        return () => document.removeEventListener('fullscreenchange', sync);
    }, []);

    if (!can) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={() =>
                full
                    ? void document.exitFullscreen().catch(() => undefined)
                    : void document.documentElement
                          .requestFullscreen()
                          .catch(() => undefined)
            }
            className="ml-auto inline-flex size-11 shrink-0 items-center justify-center rounded-xl text-white/60 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-4 focus-visible:ring-(--k-signal)/50 focus-visible:outline-none"
            aria-label={full ? 'Leave full screen' : 'Full screen'}
        >
            {full ? (
                <Minimize className="size-5" />
            ) : (
                <Maximize className="size-5" />
            )}
        </button>
    );
}

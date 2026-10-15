import { router } from '@inertiajs/react';
import { Check, Copy, MonitorSmartphone } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { grouped } from '../format';
import type { ManagedKiosk, PairingCode } from '../types';

/**
 * A pairing code for a kiosk (ADR 0092): shown once, working once, for fifteen
 * minutes. Whoever is at the tablet opens the kiosk page and types it; any
 * device that was this kiosk before stops being it.
 */
export function PairingCodeDialog({
    kiosk,
    pairing,
    error,
    open,
    onOpenChange,
}: {
    kiosk: ManagedKiosk | null;
    /** Asked for by whoever opened the dialog — each ask spends the last code. */
    pairing: PairingCode | null;
    error: string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Modal
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);

                if (!next) {
                    // The row says whether the code was used.
                    router.reload({ only: ['kiosks'] });
                }
            }}
        >
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <MonitorSmartphone />
                        </ModalIcon>
                    }
                    title={
                        kiosk
                            ? `Pair a device as “${kiosk.name}”`
                            : 'Pair a device'
                    }
                    description={
                        kiosk?.status === 'paired'
                            ? 'The device that types this code becomes the kiosk, and the one that is now stops being it.'
                            : 'The device that types this code becomes the kiosk.'
                    }
                />
                {open && (
                    <Body
                        pairing={pairing}
                        error={error}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    pairing,
    error,
    onDone,
}: {
    pairing: PairingCode | null;
    error: string | null;
    onDone: () => void;
}) {
    const [now, setNow] = useState(() => Date.now());
    const [copied, copy] = useClipboard();

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(id);
    }, []);

    const left = pairing
        ? Math.max(0, Math.round((Date.parse(pairing.expires_at) - now) / 1000))
        : 0;
    const expired = pairing !== null && left === 0;

    return (
        <>
            <ModalBody className="space-y-5">
                {error ? (
                    <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
                        {error}
                    </p>
                ) : !pairing ? (
                    <div className="flex h-28 items-center justify-center">
                        <Spinner />
                    </div>
                ) : (
                    <>
                        <ol className="space-y-4 text-sm">
                            <li className="flex gap-3">
                                <Step n={1} />
                                <div className="min-w-0 flex-1 space-y-1.5">
                                    <p>On the tablet, open this page:</p>
                                    <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2">
                                        <code className="min-w-0 flex-1 truncate text-sm">
                                            {pairing.url}
                                        </code>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                void copy(pairing.url)
                                            }
                                            className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                            aria-label="Copy the kiosk page’s address"
                                        >
                                            {copied === pairing.url ? (
                                                <Check className="size-4 text-emerald-600" />
                                            ) : (
                                                <Copy className="size-4" />
                                            )}
                                        </button>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Nobody should be signed in to Synapse in
                                        that browser.
                                    </p>
                                </div>
                            </li>
                            <li className="flex gap-3">
                                <Step n={2} />
                                <div className="min-w-0 flex-1 space-y-2">
                                    <p>Type this code there:</p>
                                    <p
                                        aria-label={`Pairing code ${pairing.code.split('').join(' ')}`}
                                        className={
                                            expired
                                                ? 'text-4xl font-semibold tracking-[0.12em] text-muted-foreground tabular-nums line-through'
                                                : 'text-4xl font-semibold tracking-[0.12em] text-[#0F2044] tabular-nums dark:text-white'
                                        }
                                    >
                                        {grouped(pairing.code)}
                                    </p>
                                    <p
                                        className="text-xs text-muted-foreground tabular-nums"
                                        aria-live="polite"
                                    >
                                        {expired
                                            ? 'This code has run out. Close this and ask for another.'
                                            : `Works once, for ${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')} more.`}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <Button type="button" onClick={onDone}>
                    Done
                </Button>
            </ModalFooter>
        </>
    );
}

function Step({ n }: { n: number }) {
    return (
        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#0ABFBF]/15 text-xs font-semibold text-[#08767c] dark:text-[#0ABFBF]">
            {n}
        </span>
    );
}

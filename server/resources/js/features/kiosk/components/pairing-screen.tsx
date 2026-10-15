import { REGEXP_ONLY_DIGITS_AND_CHARS } from 'input-otp';
import { MonitorSmartphone } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import * as api from '../api';
import type { KioskInfo } from '../types';
import { KioskMessage } from './card-panel';

/**
 * A device that is not a kiosk yet (ADR 0092). HR asks Synapse for a pairing
 * code on Attendance → Kiosks and types it here; the code works once, for
 * fifteen minutes, and the device keeps a secret of its own from then on.
 */
export function PairingScreen({
    codeLength,
    onPaired,
    note,
}: {
    codeLength: number;
    onPaired: (kiosk: KioskInfo) => void;
    /** Why the kiosk is back here — HR signed it out, say. */
    note?: string | null;
}) {
    const [code, setCode] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const half = Math.ceil(codeLength / 2);

    const submit = async (value: string) => {
        if (value.length !== codeLength || busy) {
            return;
        }

        setBusy(true);
        setMessage(null);
        const outcome = await api.pair(value);
        setBusy(false);

        if (outcome.ok) {
            onPaired(outcome.data.kiosk);
        } else {
            setMessage(outcome.message);
            setCode('');
        }
    };

    return (
        <div className="w-full max-w-xl">
            <AppLogoIcon surface="dark" className="mb-10 h-7 w-auto" />

            <div className="flex items-start gap-4">
                <span className="hidden size-12 shrink-0 items-center justify-center rounded-2xl bg-(--k-ground-raised) text-(--k-signal) sm:flex">
                    <MonitorSmartphone className="size-6" />
                </span>
                <div className="space-y-2">
                    <h1 className="text-[clamp(1.9rem,4vw,2.6rem)] leading-tight font-semibold tracking-tight">
                        Set up this kiosk
                    </h1>
                    <p className="max-w-[46ch] text-lg text-white/70">
                        In Synapse, open Attendance, then Kiosks. Choose this
                        kiosk, pick{' '}
                        <span className="text-white">Pair a device</span>, and
                        type the code it shows.
                    </p>
                </div>
            </div>

            <form
                className="mt-10 space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit(code);
                }}
            >
                <label htmlFor="pairing-code" className="sr-only">
                    Pairing code
                </label>
                <InputOTP
                    id="pairing-code"
                    maxLength={codeLength}
                    value={code}
                    onChange={(value) => setCode(value.toUpperCase())}
                    onComplete={(value: string) =>
                        void submit(value.toUpperCase())
                    }
                    pattern={REGEXP_ONLY_DIGITS_AND_CHARS}
                    inputMode="text"
                    autoFocus
                    disabled={busy}
                    containerClassName="gap-3"
                >
                    {[0, half].map((start) => (
                        <InputOTPGroup key={start}>
                            {Array.from(
                                {
                                    length:
                                        start === 0 ? half : codeLength - half,
                                },
                                (_, offset) => (
                                    <InputOTPSlot
                                        key={start + offset}
                                        index={start + offset}
                                        className="h-16 w-12 border-white/20 bg-white/5 text-2xl font-semibold text-white uppercase sm:h-[4.5rem] sm:w-14"
                                    />
                                ),
                            )}
                        </InputOTPGroup>
                    ))}
                </InputOTP>

                {(message ?? note) && (
                    <div className="max-w-md">
                        <KioskMessage
                            tone={message ? 'refused' : 'note'}
                            surface="navy"
                        >
                            {message ?? note}
                        </KioskMessage>
                    </div>
                )}

                <button
                    type="submit"
                    disabled={busy || code.length !== codeLength}
                    className="inline-flex h-14 items-center gap-2 rounded-2xl bg-(--k-signal) px-6 text-lg font-semibold text-(--k-ink) transition-[filter] hover:brightness-105 focus-visible:ring-4 focus-visible:ring-white/50 focus-visible:outline-none disabled:opacity-40"
                >
                    {busy && <Spinner />}
                    Pair this device
                </button>
            </form>

            <p className="mt-12 max-w-[52ch] text-sm text-white/45">
                Once paired, anyone at this device clocks in with their employee
                number and PIN. Nobody signs in to Synapse here.
            </p>
        </div>
    );
}

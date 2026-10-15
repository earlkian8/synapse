import { ArrowLeft } from 'lucide-react';
import type { KioskController } from '../hooks/use-kiosk';
import { CardPanel, KioskMessage } from './card-panel';
import { EntryDisplay } from './entry-display';
import { Keypad } from './keypad';

/**
 * The first two steps: the employee number, then the PIN. The kiosk names
 * nobody until both match, so this panel never says whose a number is.
 */
export function SignInPanel({ kiosk }: { kiosk: KioskController }) {
    const { step, entry, message, busy, pinLength } = kiosk;
    const onPin = step.kind === 'pin';

    return (
        <CardPanel
            footer={
                onPin ? (
                    <button
                        type="button"
                        onClick={kiosk.back}
                        className="inline-flex h-12 items-center gap-2 self-start rounded-xl px-3 text-white/75 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-4 focus-visible:ring-(--k-signal)/50 focus-visible:outline-none"
                    >
                        <ArrowLeft className="size-5" />
                        Change the number
                    </button>
                ) : null
            }
        >
            <div className="mb-5 space-y-1">
                <h1 className="text-[1.6rem] leading-tight font-semibold">
                    {onPin ? 'Your PIN' : 'Your employee number'}
                </h1>
                <p className="text-[0.95rem] text-(--k-muted)">
                    {onPin
                        ? `The ${pinLength} digits only you know.`
                        : 'The digits are enough: 42 for EMP-00042.'}
                </p>
            </div>

            <div className="space-y-4">
                <EntryDisplay
                    value={entry}
                    secret={onPin}
                    length={onPin ? pinLength : undefined}
                    label={onPin ? 'PIN' : 'Employee number'}
                    shake={onPin && message !== null}
                />

                {message && <KioskMessage>{message}</KioskMessage>}

                <Keypad
                    onDigit={kiosk.press}
                    onErase={kiosk.erase}
                    onSubmit={onPin ? undefined : kiosk.next}
                    submitDisabled={entry === ''}
                    disabled={busy}
                />
            </div>
        </CardPanel>
    );
}

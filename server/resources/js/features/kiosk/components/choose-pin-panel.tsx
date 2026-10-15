import { KeyRound } from 'lucide-react';
import type { KioskController } from '../hooks/use-kiosk';
import { CardPanel, KioskMessage } from './card-panel';
import { EntryDisplay } from './entry-display';
import { Keypad } from './keypad';

/**
 * The first time somebody uses the PIN HR gave them: they choose their own,
 * twice, so the PIN that clocks them in is one HR never saw.
 */
export function ChoosePinPanel({ kiosk }: { kiosk: KioskController }) {
    const { step, entry, message, busy, pinLength } = kiosk;

    if (step.kind !== 'choose') {
        return null;
    }

    const confirming = step.first !== null;

    return (
        <CardPanel
            footer={
                <button
                    type="button"
                    onClick={() => kiosk.reset()}
                    className="h-12 self-start rounded-xl px-3 text-white/75 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-4 focus-visible:ring-(--k-signal)/50 focus-visible:outline-none"
                >
                    Not now
                </button>
            }
        >
            <div className="mb-5 flex items-start gap-3">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-(--k-in)/10 text-(--k-in)">
                    <KeyRound className="size-5" />
                </span>
                <div className="space-y-1">
                    <h1 className="text-[1.6rem] leading-tight font-semibold">
                        {confirming
                            ? 'Type it once more'
                            : `Choose your PIN, ${step.session.person.first_name}`}
                    </h1>
                    <p className="text-[0.95rem] text-(--k-muted)">
                        {confirming
                            ? 'The same six digits, to be sure.'
                            : `${pinLength} digits only you will know. You’ll use them every time you clock in here.`}
                    </p>
                </div>
            </div>

            <div className="space-y-4">
                <EntryDisplay
                    value={entry}
                    secret
                    length={pinLength}
                    label={confirming ? 'Your new PIN again' : 'Your new PIN'}
                    shake={message !== null}
                />

                {message && <KioskMessage>{message}</KioskMessage>}

                <Keypad
                    onDigit={kiosk.press}
                    onErase={kiosk.erase}
                    disabled={busy}
                />
            </div>
        </CardPanel>
    );
}

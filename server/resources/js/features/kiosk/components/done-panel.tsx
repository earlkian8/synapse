import { PUNCH_WORDS, sendOff } from '../constants';
import { DONE_MS } from '../hooks/use-kiosk';
import type { KioskController } from '../hooks/use-kiosk';
import { clockTime } from '../hooks/use-kiosk-clock';
import { CardPanel, DrainBar } from './card-panel';
import { TimeCard } from './time-card';

/**
 * The punch, landed: what was recorded, in its ink and large enough to read from
 * a step back, stamped onto the person's card. The kiosk moves on by itself.
 */
export function DonePanel({ kiosk }: { kiosk: KioskController }) {
    const { step } = kiosk;

    if (step.kind !== 'done') {
        return null;
    }

    const { result, session } = step;
    const words = PUNCH_WORDS[result.type];
    const zone = kiosk.kiosk.timezone;

    return (
        <CardPanel
            footer={
                <div className="flex flex-col gap-3">
                    <button
                        type="button"
                        onClick={() => kiosk.reset()}
                        className="h-14 rounded-2xl bg-white/10 text-lg font-semibold text-white transition-colors hover:bg-white/15 focus-visible:ring-4 focus-visible:ring-(--k-signal)/50 focus-visible:outline-none"
                    >
                        Done
                    </button>
                    <DrainBar
                        ms={DONE_MS}
                        label="Time before the next person"
                    />
                </div>
            }
        >
            <div role="status" aria-live="assertive">
                <p
                    className="text-[1.15rem] font-semibold"
                    style={{ color: words.ink }}
                >
                    {words.done}
                </p>
                <p className="text-[clamp(3rem,7vw,4.25rem)] leading-none font-semibold tracking-[-0.03em] tabular-nums">
                    {clockTime(result.at, zone)}
                </p>
                <p className="mt-2 text-[1.05rem] text-(--k-muted)">
                    {sendOff(result.type, result.first_name)}
                </p>
            </div>

            <div className="mt-6">
                <TimeCard
                    day={session.day}
                    timeZone={zone}
                    stampedAt={result.at}
                />
            </div>
        </CardPanel>
    );
}

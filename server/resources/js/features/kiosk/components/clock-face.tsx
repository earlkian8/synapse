import { zoned } from '../hooks/use-kiosk-clock';

/**
 * The bundy clock's face: the time on the kiosk's own clock, large enough to
 * read from the back of a queue, with the seconds ticking beside it — a punch is
 * stamped to the minute, and people check it to the second.
 */
export function ClockFace({ now, timeZone }: { now: Date; timeZone: string }) {
    const t = zoned(now, timeZone);

    return (
        <div className="select-none">
            <p className="sr-only">
                {t.hour}:{t.minute} {t.period}, {t.weekday} {t.date}
            </p>
            <div aria-hidden className="flex items-start gap-3 sm:gap-4">
                <span className="text-[clamp(4.75rem,14vw,11.5rem)] leading-[0.82] font-medium tracking-[-0.045em] tabular-nums">
                    {t.hour}
                    <span className="text-(--k-signal)">:</span>
                    {t.minute}
                </span>
                <span className="flex flex-col gap-1 pt-[0.4em] text-[clamp(1.1rem,2.2vw,1.9rem)] leading-none">
                    <span className="font-semibold">{t.period}</span>
                    <span className="text-white/45 tabular-nums">
                        {t.second}
                    </span>
                </span>
            </div>
            <p
                aria-hidden
                className="mt-5 text-[clamp(1.05rem,1.8vw,1.5rem)] text-white/70"
            >
                {t.weekday}, {t.date}
            </p>
        </div>
    );
}

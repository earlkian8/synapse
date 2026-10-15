import { Camera, CameraOff } from 'lucide-react';
import type { RefObject } from 'react';
import { cn } from '@/lib/utils';
import { PUNCH_WORDS, greeting } from '../constants';
import type { CameraState } from '../hooks/use-camera';
import type { KioskController } from '../hooks/use-kiosk';
import { DrainBar, CardPanel, KioskMessage } from './card-panel';
import { TimeCard } from './time-card';

/**
 * Somebody signed in: their day on its time card, and the punch they most
 * likely came for as the one big button, with whatever else the day allows
 * beneath it. The camera's frame is taken as they tap.
 */
export function PersonPanel({
    kiosk,
    hour,
    videoRef,
    cameraStatus,
    capture,
    idleMs,
}: {
    kiosk: KioskController;
    hour: number;
    videoRef: RefObject<HTMLVideoElement | null>;
    cameraStatus: CameraState;
    capture: () => Promise<Blob | null>;
    idleMs: number;
}) {
    const { step, busy, message } = kiosk;

    if (step.kind !== 'person') {
        return null;
    }

    const { person, day } = step.session;
    const primary = day.next_expected;
    const others = day.allowed.filter((type) => type !== primary);
    const noCamera =
        cameraStatus === 'unavailable' || cameraStatus === 'denied';
    const blocked = !day.kiosk_allowed || (day.photo_required && noCamera);

    const punch = async (type: keyof typeof PUNCH_WORDS) =>
        kiosk.record(type, cameraStatus === 'on' ? await capture() : null);

    return (
        <CardPanel
            footer={
                <div className="flex flex-col gap-3">
                    <button
                        type="button"
                        onClick={() => kiosk.reset()}
                        className="h-12 self-start rounded-xl px-3 text-white/75 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-4 focus-visible:ring-(--k-signal)/50 focus-visible:outline-none"
                    >
                        Not {person.first_name}? Start over
                    </button>
                    <DrainBar
                        key={kiosk.touched}
                        ms={idleMs}
                        label="Time before this kiosk signs you out"
                    />
                </div>
            }
        >
            <div className="flex items-center gap-3.5">
                <span className="flex size-14 shrink-0 items-center justify-center rounded-full bg-(--k-ink) text-lg font-semibold text-white">
                    {person.initials}
                </span>
                <div className="min-w-0">
                    <h1 className="truncate text-[1.6rem] leading-tight font-semibold">
                        {greeting(hour)}, {person.first_name}
                    </h1>
                    <p className="text-[0.95rem] text-(--k-muted)">
                        {day.shift ?? 'No shift today'}
                    </p>
                </div>
            </div>

            <div className="mt-5">
                <TimeCard day={day} timeZone={kiosk.kiosk.timezone} />
            </div>

            <div className="mt-5 space-y-3">
                {!day.kiosk_allowed ? (
                    <KioskMessage>
                        Your attendance policy doesn’t let you punch at a kiosk.
                        Ask HR how you should clock in.
                    </KioskMessage>
                ) : day.photo_required && noCamera ? (
                    <KioskMessage>
                        Your attendance policy needs a photo with every punch,
                        and this kiosk’s camera isn’t working. Ask HR.
                    </KioskMessage>
                ) : null}

                {message && <KioskMessage>{message}</KioskMessage>}

                {primary && !blocked && (
                    <PunchButton
                        type={primary}
                        size="primary"
                        disabled={busy}
                        onPunch={punch}
                    />
                )}
                {others.length > 0 && !blocked && (
                    <div
                        className={cn(
                            'grid gap-2.5',
                            others.length > 1 && 'grid-cols-2',
                        )}
                    >
                        {others.map((type) => (
                            <PunchButton
                                key={type}
                                type={type}
                                size="secondary"
                                disabled={busy}
                                onPunch={punch}
                            />
                        ))}
                    </div>
                )}
                {!primary && day.allowed.length === 0 && (
                    <KioskMessage tone="note">
                        You’ve clocked out for the day.
                    </KioskMessage>
                )}
            </div>

            <CameraLine
                videoRef={videoRef}
                status={cameraStatus}
                required={day.photo_required}
            />
        </CardPanel>
    );
}

function PunchButton({
    type,
    size,
    disabled,
    onPunch,
}: {
    type: keyof typeof PUNCH_WORDS;
    size: 'primary' | 'secondary';
    disabled: boolean;
    onPunch: (type: keyof typeof PUNCH_WORDS) => void;
}) {
    const words = PUNCH_WORDS[type];
    const Icon = words.icon;

    return (
        <button
            type="button"
            disabled={disabled}
            onClick={() => onPunch(type)}
            style={
                size === 'primary'
                    ? { backgroundColor: words.ink }
                    : { color: words.ink, borderColor: words.ink }
            }
            className={cn(
                'flex w-full items-center justify-center gap-2.5 rounded-2xl font-semibold transition-[transform,filter] select-none focus-visible:ring-4 focus-visible:ring-(--k-signal)/45 focus-visible:outline-none active:translate-y-px disabled:opacity-50',
                size === 'primary'
                    ? 'h-[4.5rem] text-[1.45rem] text-white shadow-[inset_0_-4px_0_rgb(0_0_0/0.18)] active:brightness-95'
                    : 'h-14 border-2 bg-white text-lg',
            )}
        >
            <Icon className={size === 'primary' ? 'size-6' : 'size-5'} />
            {words.action}
        </button>
    );
}

/**
 * The camera, said plainly: its picture while it is on, so people know what it
 * takes; and nothing to worry about when the kiosk has none and nobody needs it.
 */
function CameraLine({
    videoRef,
    status,
    required,
}: {
    videoRef: RefObject<HTMLVideoElement | null>;
    status: CameraState;
    required: boolean;
}) {
    const live = status === 'on' || status === 'starting';

    if (!live && !required) {
        return null;
    }

    return (
        <div className="mt-5 flex items-center gap-3 border-t border-(--k-rule) pt-4">
            <span className="relative flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-(--k-ink)/8 text-(--k-muted)">
                {live ? (
                    <video
                        ref={videoRef}
                        muted
                        playsInline
                        className="size-full -scale-x-100 object-cover"
                    />
                ) : (
                    <CameraOff className="size-5" />
                )}
            </span>
            <p className="flex items-center gap-1.5 text-sm text-(--k-muted)">
                {live && <Camera className="size-4 shrink-0" />}
                {live
                    ? 'Your photo is taken when you punch.'
                    : 'The camera isn’t available.'}
            </p>
        </div>
    );
}

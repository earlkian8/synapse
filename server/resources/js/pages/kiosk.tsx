import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ChoosePinPanel } from '@/features/kiosk/components/choose-pin-panel';
import { ClockFace } from '@/features/kiosk/components/clock-face';
import { DonePanel } from '@/features/kiosk/components/done-panel';
import { KioskFrame } from '@/features/kiosk/components/kiosk-frame';
import { PairingScreen } from '@/features/kiosk/components/pairing-screen';
import { PersonPanel } from '@/features/kiosk/components/person-panel';
import { SignInPanel } from '@/features/kiosk/components/sign-in-panel';
import { SignedInScreen } from '@/features/kiosk/components/signed-in-screen';
import { useCamera } from '@/features/kiosk/hooks/use-camera';
import { IDLE_MS, useKiosk } from '@/features/kiosk/hooks/use-kiosk';
import { useKioskClock, zoned } from '@/features/kiosk/hooks/use-kiosk-clock';
import { useWakeLock } from '@/features/kiosk/hooks/use-wake-lock';
import type {
    KioskInfo,
    KioskPageProps,
    KioskPageState,
} from '@/features/kiosk/types';

/**
 * The kiosk (ADR 0092): a shared tablet at a site where people who have no phone
 * clock in, with their employee number and a PIN. Nobody signs in to Synapse
 * here. It opens on pairing for a device that is not a kiosk yet, on a request to
 * sign out in a browser somebody is signed in on, and on the clock otherwise.
 */
export default function Kiosk() {
    const props = usePage<KioskPageProps>().props;
    const [state, setState] = useState<KioskPageState>(props.state);
    const [info, setInfo] = useState<KioskInfo | null>(props.kiosk);
    const [note, setNote] = useState<string | null>(null);
    // A fresh turn whenever the device is paired again.
    const [generation, setGeneration] = useState(0);

    return (
        <>
            <Head title={info ? info.name : 'Kiosk'}>
                <meta name="robots" content="noindex" />
            </Head>

            {state === 'signed_in' ? (
                <KioskFrame kiosk={null}>
                    <SignedInScreen />
                </KioskFrame>
            ) : state === 'unpaired' || info === null ? (
                <KioskFrame kiosk={null}>
                    <PairingScreen
                        codeLength={props.codeLength}
                        note={note}
                        onPaired={(kiosk) => {
                            setInfo(kiosk);
                            setNote(null);
                            setGeneration((n) => n + 1);
                            setState('ready');
                        }}
                    />
                </KioskFrame>
            ) : (
                <KioskTurn
                    key={generation}
                    initial={info}
                    onLost={(lost) => {
                        setNote(
                            lost === 'unpaired'
                                ? 'This device was signed out of its kiosk. Pair it again to keep clocking people in.'
                                : null,
                        );
                        setState(lost);
                    }}
                />
            )}
        </>
    );
}

function KioskTurn({
    initial,
    onLost,
}: {
    initial: KioskInfo;
    onLost: (state: Exclude<KioskPageState, 'ready'>) => void;
}) {
    const kiosk = useKiosk(initial, onLost);
    const now = useKioskClock(kiosk.offset);
    const { video, status, capture } = useCamera(kiosk.step.kind === 'person');

    useWakeLock(true);

    const { step } = kiosk;

    return (
        <KioskFrame
            kiosk={kiosk.kiosk}
            clock={<ClockFace now={now} timeZone={kiosk.kiosk.timezone} />}
        >
            {(step.kind === 'number' || step.kind === 'pin') && (
                <SignInPanel kiosk={kiosk} />
            )}
            {step.kind === 'choose' && <ChoosePinPanel kiosk={kiosk} />}
            {step.kind === 'person' && (
                <PersonPanel
                    kiosk={kiosk}
                    hour={zoned(now, kiosk.kiosk.timezone).hour24}
                    videoRef={video}
                    cameraStatus={status}
                    capture={capture}
                    idleMs={IDLE_MS.person}
                />
            )}
            {step.kind === 'done' && <DonePanel kiosk={kiosk} />}
        </KioskFrame>
    );
}

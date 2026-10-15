import { useEffect, useRef, useState } from 'react';

export type CameraState = 'off' | 'starting' | 'on' | 'unavailable' | 'denied';

/** The widest a kiosk photo is sent at — a face, not a portrait. */
const PHOTO_WIDTH = 640;

function cameraSupported(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        typeof navigator.mediaDevices?.getUserMedia === 'function'
    );
}

/**
 * The kiosk's camera, on only while somebody is signed in at it (ADR 0092): it
 * takes the photo a punch carries, and is off the rest of the time, so the
 * kiosk is not watching an empty doorway. `capture()` takes a frame as a JPEG.
 */
export function useCamera(active: boolean) {
    const video = useRef<HTMLVideoElement>(null);
    const [state, setState] = useState<Exclude<CameraState, 'off'>>('starting');
    const supported = cameraSupported();

    useEffect(() => {
        const element = video.current;

        if (!active || !supported) {
            return;
        }

        let stream: MediaStream | null = null;
        let cancelled = false;

        navigator.mediaDevices
            .getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 } },
                audio: false,
            })
            .then((granted) => {
                if (cancelled) {
                    granted.getTracks().forEach((track) => track.stop());

                    return;
                }

                stream = granted;

                if (element) {
                    element.srcObject = granted;
                    void element.play().catch(() => undefined);
                }

                setState('on');
            })
            .catch((error: unknown) => {
                if (!cancelled) {
                    setState(
                        error instanceof DOMException &&
                            error.name === 'NotAllowedError'
                            ? 'denied'
                            : 'unavailable',
                    );
                }
            });

        return () => {
            cancelled = true;
            stream?.getTracks().forEach((track) => track.stop());

            if (element) {
                element.srcObject = null;
            }
        };
    }, [active, supported]);

    const capture = async (): Promise<Blob | null> => {
        const element = video.current;

        if (!element || element.videoWidth === 0) {
            return null;
        }

        const scale = Math.min(1, PHOTO_WIDTH / element.videoWidth);
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(element.videoWidth * scale);
        canvas.height = Math.round(element.videoHeight * scale);
        canvas
            .getContext('2d')
            ?.drawImage(element, 0, 0, canvas.width, canvas.height);

        return new Promise((resolve) =>
            canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.82),
        );
    };

    const status: CameraState = !supported
        ? 'unavailable'
        : !active
          ? 'off'
          : state;

    return { video, status, capture };
}

import { useSyncExternalStore } from 'react';
import { findTourTarget } from './targets';
import type { TourTarget } from './targets';
import type { TourRect } from './types';

/**
 * Re-read the target once a frame while a stop is showing. Scrolling, resizing,
 * the sidebar folding to icons, a font arriving late — anything that moves the
 * target moves the spotlight with it, and nothing needs a listener of its own.
 * The loop only runs while the tour points at something.
 */
function subscribeToFrames(onChange: () => void): () => void {
    let frame = window.requestAnimationFrame(function loop() {
        onChange();
        frame = window.requestAnimationFrame(loop);
    });

    return () => window.cancelAnimationFrame(frame);
}

function subscribeToNothing(): () => void {
    return () => {};
}

/** The last reading, so an unchanged target returns the same object. */
let cached: { key: string; rect: TourRect } | null = null;

function measure(target: TourTarget | null): TourRect | null {
    const element = target ? findTourTarget(target) : null;

    if (!element) {
        return null;
    }

    const box = element.getBoundingClientRect();
    const rect = {
        top: Math.round(box.top),
        left: Math.round(box.left),
        width: Math.round(box.width),
        height: Math.round(box.height),
    };
    const key = `${target}:${rect.top}:${rect.left}:${rect.width}:${rect.height}`;

    if (cached?.key !== key) {
        cached = { key, rect };
    }

    return cached.rect;
}

/** `rect` grown by `padding` on every side — the room a spotlight leaves. */
export function padRect(rect: TourRect, padding: number): TourRect {
    return {
        top: rect.top - padding,
        left: rect.left - padding,
        width: rect.width + padding * 2,
        height: rect.height + padding * 2,
    };
}

/**
 * Where `target` is on screen, or null when it is not there to point at.
 *
 * Read synchronously during render rather than after it, so moving to the next
 * stop never shows a frame with no measurement — the spotlight goes straight
 * from one target to the next.
 */
export function useTargetRect(target: TourTarget | null): TourRect | null {
    return useSyncExternalStore(
        target ? subscribeToFrames : subscribeToNothing,
        () => measure(target),
        () => null,
    );
}

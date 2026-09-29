import type { CSSProperties } from 'react';
import { createPortal } from 'react-dom';
import type { TourRect } from '../types';

type Props = {
    /** The lit area, or null to dim the whole screen (a centred card). */
    rect: TourRect | null;
    radius: number;
};

/**
 * The dimmed screen with a window cut around the current stop.
 *
 * One element does it: a box whose enormous box-shadow is the dim, so the
 * window is simply the box. Moving between stops is then a transition on the
 * box — the light glides from the sidebar to the top bar rather than jumping —
 * and a centred card is the same box shrunk to nothing mid-screen.
 *
 * Purely visual: it takes no pointer events and is hidden from assistive tech.
 * What keeps the page from being clicked is the card, which is modal.
 */
export function TourSpotlight({ rect, radius }: Props) {
    const style: CSSProperties = rect
        ? {
              top: rect.top,
              left: rect.left,
              width: rect.width,
              height: rect.height,
              borderRadius: Math.min(radius, rect.width / 2, rect.height / 2),
              boxShadow:
                  '0 0 0 9999px var(--tour-dim), 0 0 0 2px #0ABFBF, 0 0 28px 6px rgb(10 191 191 / 0.35)',
          }
        : {
              top: '50%',
              left: '50%',
              width: 0,
              height: 0,
              borderRadius: 9999,
              boxShadow: '0 0 0 9999px var(--tour-dim)',
          };

    return createPortal(
        <div
            aria-hidden
            data-slot="tour-spotlight"
            className="pointer-events-none fixed inset-0 z-[60] animate-in duration-300 fade-in-0 [--tour-dim:rgb(9_22_50/0.58)] motion-reduce:animate-none dark:[--tour-dim:rgb(0_0_0/0.7)]"
        >
            <div
                className="absolute transition-[top,left,width,height,border-radius,box-shadow] duration-[420ms] ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                style={style}
            />
        </div>,
        document.body,
    );
}

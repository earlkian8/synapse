import { useEffect, useState } from 'react';

/**
 * Which of an article's sections the reader is in — the last heading that has
 * reached the upper part of the screen, or the first while none has. Drives the highlight in "On this page".
 */
export function useActiveHeading(ids: string[]): string | null {
    const [active, setActive] = useState<string | null>(ids[0] ?? null);
    const key = ids.join('|');

    useEffect(() => {
        const headings = key
            .split('|')
            .map((id) => document.getElementById(id))
            .filter((element): element is HTMLElement => element !== null);

        if (headings.length === 0) {
            return;
        }

        // A heading in the upper part of the screen is the section being read.
        const offset = 96;

        const update = () => {
            const reached = Math.min(window.innerHeight * 0.3, 260);
            let current = headings[0].id;

            for (const heading of headings) {
                if (heading.getBoundingClientRect().top <= reached) {
                    current = heading.id;
                }
            }

            // Scrolled to the very bottom: the last section is the one being read.
            if (
                window.innerHeight + window.scrollY >=
                document.documentElement.scrollHeight - 2
            ) {
                current = headings[headings.length - 1].id;
            }

            setActive(current);
        };

        const observer = new IntersectionObserver(update, {
            rootMargin: `-${offset}px 0px -60% 0px`,
        });

        headings.forEach((heading) => observer.observe(heading));
        window.addEventListener('scroll', update, { passive: true });

        return () => {
            observer.disconnect();
            window.removeEventListener('scroll', update);
        };
    }, [key]);

    return active;
}

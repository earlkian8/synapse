import { useEffect } from 'react';

/** "Organisations — SYNAPSE Admin" in the tab. */
export function usePageTitle(title: string): void {
    useEffect(() => {
        document.title = `${title} — SYNAPSE Admin`;
    }, [title]);
}

/**
 * Split a line of text into the parts that match a search and the parts that
 * don't, for drawing the matches in bold. Matching ignores case, like the
 * search itself.
 */
export function highlightParts(
    text: string,
    terms: string[],
): { text: string; match: boolean }[] {
    const words = terms
        .filter((term) => term.length > 1)
        .map((term) => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

    if (words.length === 0) {
        return [{ text, match: false }];
    }

    const pattern = new RegExp(`(${words.join('|')})`, 'gi');

    return text
        .split(pattern)
        .filter((part) => part !== '')
        .map((part) => ({
            text: part,
            match: words.some((word) =>
                new RegExp(`^${word}$`, 'i').test(part),
            ),
        }));
}

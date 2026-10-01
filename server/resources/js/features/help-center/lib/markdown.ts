import type { HelpHeading } from '../types';

/**
 * The few Markdown helpers the Help Center needs on top of react-markdown:
 * stable anchors for an article's headings (so "On this page" and a shared
 * link can point at a section), and callouts — a blockquote that opens with
 * `[!NOTE]`, `[!TIP]`, `[!IMPORTANT]` or `[!WARNING]`, as GitHub writes them.
 *
 * The heading ids are given out in document order by one slugger, and the
 * table of contents walks the same headings in the same order with another, so
 * the two always agree — including when two headings read the same.
 */

/** The kinds of callout an article can use. */
export type CalloutKind = 'note' | 'tip' | 'important' | 'warning';

const CALLOUT = /^\[!(NOTE|TIP|IMPORTANT|WARNING)\][ \t]*\n?/i;

/** A heading's words as an anchor: "Who can do what" → "who-can-do-what". */
export function slugify(text: string): string {
    return (
        text
            .normalize('NFKD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'section'
    );
}

/** Hands out unique anchors: the second "Related" becomes "related-2". */
function createSlugger(): (text: string) => string {
    const seen = new Map<string, number>();

    return (text) => {
        const base = slugify(text);
        const count = (seen.get(base) ?? 0) + 1;
        seen.set(base, count);

        return count === 1 ? base : `${base}-${count}`;
    };
}

/** A heading's text with its inline Markdown taken off. */
function plainHeading(text: string): string {
    return text
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/`([^`]*)`/g, '$1')
        .replace(/[*_]{1,3}([^*_]+)[*_]{1,3}/g, '$1')
        .trim();
}

/**
 * The second- and third-level headings of an article, in order, with the
 * anchors {@link remarkHelp} gives them.
 */
export function headingsOf(markdown: string): HelpHeading[] {
    const slug = createSlugger();
    const headings: HelpHeading[] = [];
    let fenced = false;

    for (const line of markdown.split('\n')) {
        if (/^\s*(```|~~~)/.test(line)) {
            fenced = !fenced;

            continue;
        }

        const match = fenced ? null : /^(#{2,3})\s+(.+?)\s*#*\s*$/.exec(line);

        if (match) {
            const title = plainHeading(match[2]);

            headings.push({
                id: slug(title),
                title,
                level: match[1].length as 2 | 3,
            });
        }
    }

    return headings;
}

/** The parts of a Markdown syntax tree this plugin touches. */
type MdNode = {
    type: string;
    value?: string;
    depth?: number;
    children?: MdNode[];
    data?: { hName?: string; hProperties?: Record<string, unknown> };
};

/** All the text inside a node, as it reads. */
function textOf(node: MdNode): string {
    if (node.type === 'text' || node.type === 'inlineCode') {
        return node.value ?? '';
    }

    return (node.children ?? []).map(textOf).join('');
}

/**
 * A remark plugin: anchors on `##` and `###` headings, and callouts drawn as
 * `<aside data-callout="tip">` with their marker removed.
 */
export function remarkHelp() {
    return (tree: unknown): void => {
        const slug = createSlugger();

        const visit = (node: MdNode): void => {
            if (
                node.type === 'heading' &&
                (node.depth === 2 || node.depth === 3)
            ) {
                node.data = {
                    ...node.data,
                    hProperties: {
                        ...node.data?.hProperties,
                        id: slug(textOf(node).trim()),
                    },
                };
            }

            if (node.type === 'blockquote') {
                const paragraph = node.children?.[0];
                const first = paragraph?.children?.[0];
                const marker =
                    paragraph?.type === 'paragraph' && first?.type === 'text'
                        ? CALLOUT.exec(first.value ?? '')
                        : null;

                if (paragraph && first && marker) {
                    first.value = (first.value ?? '').slice(marker[0].length);

                    if (first.value === '') {
                        paragraph.children = paragraph.children?.slice(1);
                    }

                    if ((paragraph.children ?? []).length === 0) {
                        node.children = node.children?.slice(1);
                    }

                    node.data = {
                        ...node.data,
                        hName: 'aside',
                        hProperties: {
                            'data-callout': marker[1].toLowerCase(),
                        },
                    };
                }
            }

            node.children?.forEach(visit);
        };

        visit(tree as MdNode);
    };
}

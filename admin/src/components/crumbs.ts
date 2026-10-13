export type Crumb = { title: string; to: string };

/** Route `handle`s carry their trail: `{ crumbs: [...] }`. */
export const crumbs = (...trail: Crumb[]) => ({ crumbs: trail });

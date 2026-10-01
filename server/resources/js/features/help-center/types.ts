/**
 * The Help Center (ADR 0062). Every list here is already filtered on the
 * server to what the signed-in person may read — see `Support\Help\HelpCenter`.
 */

/** An article as a list shows it. */
export type HelpArticleSummary = {
    slug: string;
    category: string;
    category_title: string;
    title: string;
    summary: string;
    href: string;
    featured: boolean;
};

/** A link to a neighbouring article (the previous / next pager). */
export type HelpArticleLink = Pick<
    HelpArticleSummary,
    'slug' | 'title' | 'href' | 'category_title'
>;

export type HelpCategory = {
    key: string;
    title: string;
    description: string;
    /** A name from `CATEGORY_ICONS`. */
    icon: string;
    href: string;
    articles: HelpArticleSummary[];
};

export type HelpArticle = HelpArticleSummary & {
    /** Markdown, with links to articles the reader may not open already removed. */
    body: string;
    reading_minutes: number;
    related: HelpArticleSummary[];
    previous: HelpArticleLink | null;
    next: HelpArticleLink | null;
};

export type HelpSearchResult = HelpArticleSummary & {
    /** A line of the article around the first match, as plain text. */
    snippet: string;
};

export type HelpIndexPageProps = {
    categories: HelpCategory[];
    featured: HelpArticleSummary[];
};

export type HelpCategoryPageProps = {
    category: HelpCategory;
    categories: HelpCategory[];
};

export type HelpArticlePageProps = {
    article: HelpArticle;
    categories: HelpCategory[];
};

export type HelpSearchPageProps = {
    query: string;
    results: HelpSearchResult[];
    /** Nothing matched every word, so these match some of them. */
    partial: boolean;
    /** The words that were matched on, for highlighting. */
    terms: string[];
    categories: HelpCategory[];
};

/** A heading an article's "On this page" lists. */
export type HelpHeading = {
    id: string;
    title: string;
    level: 2 | 3;
};

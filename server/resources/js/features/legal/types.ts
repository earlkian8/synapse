/** The Privacy Policy or the Terms of Service (ADR 0063). */
export type LegalDocument = {
    key: 'privacy' | 'terms';
    title: string;
    summary: string;
    /** The key points, shown above the full text. */
    highlights: string[];
    /** When the current version took effect (Y-m-d), and as words. */
    effective: string;
    effective_label: string;
    /** Markdown, with the operator's details already written in. */
    body: string;
    operator: string;
    /** The other legal documents, to link between them. */
    others: { key: string; title: string; href: string }[];
};

export type LegalPageProps = {
    document: LegalDocument;
};

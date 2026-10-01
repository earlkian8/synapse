import { highlightParts } from '../lib/highlight';

/** A line of text with the words that were searched for drawn in bold. */
export function Highlighted({
    text,
    terms,
}: {
    text: string;
    terms: string[];
}) {
    return (
        <>
            {highlightParts(text, terms).map((part, index) =>
                part.match ? (
                    <mark
                        key={index}
                        className="rounded-sm bg-[#0ABFBF]/15 px-0.5 font-medium text-foreground"
                    >
                        {part.text}
                    </mark>
                ) : (
                    <span key={index}>{part.text}</span>
                ),
            )}
        </>
    );
}

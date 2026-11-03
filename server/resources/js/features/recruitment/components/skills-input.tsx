import { BadgeCheck, Globe, X } from 'lucide-react';
import { useId, useState } from 'react';
import { cn } from '@/lib/utils';
import {
    describeEscoReading,
    ESCO_SUGGEST_BELOW,
    escoReadingFor,
    useEscoReadings,
    useEscoSuggestions,
} from '../hooks/use-esco';
import { describeSkill } from '../hooks/use-skill-catalogue';
import type { SkillCatalogueApi } from '../hooks/use-skill-catalogue';

/** One suggestion, from the catalogue or from ESCO. */
type Option = {
    name: string;
    hint: string | null;
    source: 'catalogue' | 'esco';
};

/**
 * A tag input for a posting's skills (ADR 0097). Enter or a comma commits what
 * is typed, Backspace on an empty field removes the last chip, and the arrow
 * keys pick from the suggestions.
 *
 * A skill the catalogue knows is marked, and its tooltip says what else counts
 * as it (other names, the skills that prove it, the related ones). A skill it
 * doesn't know is looked up in ESCO (ADR 0100), and marked when ESCO knows it;
 * any other skill is matched by its words. When the catalogue has little to
 * suggest for what is typed, ESCO's skills are suggested after its own.
 */
export function SkillsInput({
    value,
    onChange,
    catalogue,
    exclude = [],
    placeholder,
    ...props
}: {
    value: string[];
    onChange: (skills: string[]) => void;
    catalogue: SkillCatalogueApi;
    /** Skills already chosen elsewhere (the other list), never suggested. */
    exclude?: string[];
    placeholder?: string;
    id?: string;
    'aria-describedby'?: string;
}) {
    const listId = useId();
    const [draft, setDraft] = useState('');
    const [highlight, setHighlight] = useState(-1);
    const [focused, setFocused] = useState(false);

    const readings = useEscoReadings(value, catalogue);
    const fromCatalogue = focused
        ? catalogue.suggest(draft, [...value, ...exclude])
        : [];
    const fromEsco = useEscoSuggestions(
        draft,
        focused && catalogue.ready && fromCatalogue.length < ESCO_SUGGEST_BELOW,
    );

    const taken = new Set(
        [...value, ...exclude, ...fromCatalogue.map((skill) => skill.name)].map(
            (skill) => skill.toLowerCase(),
        ),
    );
    const suggestions: Option[] = [
        ...fromCatalogue.map((skill): Option => ({
            name: skill.name,
            hint:
                skill.aliases.length > 0
                    ? skill.aliases.slice(0, 3).join(' · ')
                    : null,
            source: 'catalogue',
        })),
        ...fromEsco
            .map((suggestion): Option => ({
                name: suggestion.catalogue ?? suggestion.label,
                hint: suggestion.also ? `also “${suggestion.also}”` : null,
                source: 'esco',
            }))
            .filter((option) => !taken.has(option.name.toLowerCase())),
    ].slice(0, 9);
    const firstEsco = suggestions.findIndex(
        (option) => option.source === 'esco',
    );
    const open = suggestions.length > 0;

    const add = (raw: string) => {
        const skill = raw.trim().replace(/,$/, '').trim();

        if (
            skill &&
            !value.some((s) => s.toLowerCase() === skill.toLowerCase())
        ) {
            onChange([...value, skill]);
        }

        setDraft('');
        setHighlight(-1);
    };

    const remove = (index: number) =>
        onChange(value.filter((_, i) => i !== index));

    return (
        <div className="relative">
            <div className="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent px-2 py-1.5 focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30">
                {value.map((skill, index) => {
                    const known = catalogue.resolve(skill);
                    const reading = known
                        ? undefined
                        : escoReadingFor(readings, skill);
                    const esco = reading?.source === 'esco' ? reading : null;

                    return (
                        <span
                            key={`${skill}-${index}`}
                            title={
                                known
                                    ? describeSkill(known)
                                    : esco
                                      ? describeEscoReading(esco)
                                      : catalogue.ready
                                        ? 'Matched by its words, in any order or form.'
                                        : undefined
                            }
                            className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-xs font-medium"
                        >
                            {known && (
                                <BadgeCheck
                                    className="size-3 text-[#0ABFBF]"
                                    aria-label="Recognised skill"
                                />
                            )}
                            {esco && (
                                <Globe
                                    className="size-3 text-sky-600 dark:text-sky-400"
                                    aria-label="Recognised in ESCO"
                                />
                            )}
                            {skill}
                            <button
                                type="button"
                                onClick={() => remove(index)}
                                className="rounded-sm text-muted-foreground hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                aria-label={`Remove ${skill}`}
                            >
                                <X className="size-3" />
                            </button>
                        </span>
                    );
                })}
                <input
                    {...props}
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        open && highlight >= 0
                            ? `${listId}-${highlight}`
                            : undefined
                    }
                    value={draft}
                    onChange={(e) => {
                        setDraft(e.target.value);
                        setHighlight(-1);
                    }}
                    onFocus={() => setFocused(true)}
                    onKeyDown={(e) => {
                        if (open && e.key === 'ArrowDown') {
                            e.preventDefault();
                            setHighlight((h) => (h + 1) % suggestions.length);
                        } else if (open && e.key === 'ArrowUp') {
                            e.preventDefault();
                            setHighlight((h) =>
                                h <= 0 ? suggestions.length - 1 : h - 1,
                            );
                        } else if (e.key === 'Enter' || e.key === ',') {
                            e.preventDefault();
                            // ESCO's suggestions arrive late, so the list can
                            // change under the highlight.
                            add(suggestions[highlight]?.name ?? draft);
                        } else if (
                            e.key === 'Backspace' &&
                            draft === '' &&
                            value.length > 0
                        ) {
                            remove(value.length - 1);
                        }
                    }}
                    onBlur={() => {
                        setFocused(false);

                        if (draft) {
                            add(draft);
                        }
                    }}
                    placeholder={value.length === 0 ? placeholder : ''}
                    className="min-w-32 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                />
            </div>

            {open && (
                <ul
                    id={listId}
                    role="listbox"
                    className="absolute inset-x-0 top-full z-20 mt-1 max-h-60 overflow-y-auto rounded-md border border-border bg-popover p-1 text-sm shadow-md"
                >
                    {suggestions.map((option, index) => (
                        <li
                            key={`${option.source}-${option.name}`}
                            id={`${listId}-${index}`}
                            role="option"
                            aria-selected={index === highlight}
                            // Keep focus in the input, so the blur doesn't commit the draft first.
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => add(option.name)}
                            onMouseEnter={() => setHighlight(index)}
                            className={cn(
                                'flex cursor-pointer items-baseline justify-between gap-3 rounded-sm px-2 py-1.5',
                                index === highlight && 'bg-muted',
                                index === firstEsco &&
                                    index > 0 &&
                                    'mt-1 border-t border-border pt-2',
                            )}
                        >
                            <span className="flex min-w-0 items-center gap-1.5 font-medium">
                                {option.source === 'esco' && (
                                    <Globe
                                        className="size-3 shrink-0 text-sky-600 dark:text-sky-400"
                                        aria-label="From ESCO"
                                    />
                                )}
                                <span className="truncate">{option.name}</span>
                            </span>
                            {option.hint && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {option.hint}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

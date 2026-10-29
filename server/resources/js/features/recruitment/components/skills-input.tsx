import { BadgeCheck, X } from 'lucide-react';
import { useId, useState } from 'react';
import { cn } from '@/lib/utils';
import { describeSkill } from '../hooks/use-skill-catalogue';
import type { SkillCatalogueApi } from '../hooks/use-skill-catalogue';

/**
 * A tag input for a posting's skills (ADR 0097). Enter or a comma commits what
 * is typed, Backspace on an empty field removes the last chip, and the arrow
 * keys pick from the catalogue's suggestions.
 *
 * A skill the catalogue knows is marked, and its tooltip says what else counts
 * as it (other names, the skills that prove it, the related ones); any other
 * skill is matched by its words.
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

    const suggestions = focused
        ? catalogue.suggest(draft, [...value, ...exclude])
        : [];
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

                    return (
                        <span
                            key={`${skill}-${index}`}
                            title={
                                known
                                    ? describeSkill(known)
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
                            add(
                                open && highlight >= 0
                                    ? suggestions[highlight].name
                                    : draft,
                            );
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
                    {suggestions.map((skill, index) => (
                        <li
                            key={skill.name}
                            id={`${listId}-${index}`}
                            role="option"
                            aria-selected={index === highlight}
                            // Keep focus in the input, so the blur doesn't commit the draft first.
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => add(skill.name)}
                            onMouseEnter={() => setHighlight(index)}
                            className={cn(
                                'flex cursor-pointer items-baseline justify-between gap-3 rounded-sm px-2 py-1.5',
                                index === highlight && 'bg-muted',
                            )}
                        >
                            <span className="font-medium">{skill.name}</span>
                            {skill.aliases.length > 0 && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {skill.aliases.slice(0, 3).join(' · ')}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

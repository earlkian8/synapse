import { useEffect, useMemo, useState } from 'react';
import { fetchSkillCatalogue } from '../api';
import type { CatalogueSkill, SkillCatalogue } from '../types';

/** One request per page load, shared by every form that asks. */
let request: Promise<SkillCatalogue | null> | null = null;

/**
 * A skill's words as the server compares them (App\Support\Recruitment\Skills\Words):
 * lower case, the symbols that are part of a name kept as words (C++, C#,
 * .NET), every other mark a space.
 */
export function foldSkill(text: string): string {
    return text
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/(^|[^a-z0-9])c\+\+/g, '$1 cplusplus ')
        .replace(/(^|[^a-z0-9])c#/g, '$1 csharp ')
        .replace(/(^|[^a-z0-9])f#/g, '$1 fsharp ')
        .replace(/\b(asp|vb|ado)\.net\b/g, '$1 dotnet')
        .replace(/(^|[^a-z0-9])\.net\b/g, '$1 dotnet ')
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

function escape(word: string): string {
    return word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

type Index = {
    byKey: Map<string, CatalogueSkill>;
    core: (folded: string) => string;
    /** Each skill with its name and aliases already folded, for suggestions. */
    entries: { skill: CatalogueSkill; name: string; aliases: string[] }[];
};

function buildIndex(catalogue: SkillCatalogue): Index {
    const byKey = new Map<string, CatalogueSkill>();

    for (const skill of catalogue.skills) {
        for (const spelling of [skill.name, ...skill.aliases]) {
            const key = foldSkill(spelling).replace(/ /g, '');

            if (key && !byKey.has(key)) {
                byKey.set(key, skill);
            }
        }
    }

    const qualifiers = new RegExp(
        `^(?:(?:${catalogue.qualifiers.map(escape).join('|')})\\s+)+`,
    );
    const generic = new RegExp(
        `(?:\\s+(?:${catalogue.generic.map(escape).join('|')}))+$`,
    );

    return {
        byKey,
        core: (folded) =>
            folded.replace(qualifiers, '').replace(generic, '').trim(),
        entries: catalogue.skills.map((skill) => ({
            skill,
            name: foldSkill(skill.name),
            aliases: skill.aliases.map(foldSkill),
        })),
    };
}

/**
 * The ATS's skill catalogue (ADR 0097), for the posting form: which skill a
 * typed name means, and suggestions while typing. Loaded once, lazily; until
 * it arrives (or if it can't), nothing is recognised and nothing suggested.
 */
export function useSkillCatalogue() {
    const [catalogue, setCatalogue] = useState<SkillCatalogue | null>(null);

    useEffect(() => {
        let active = true;

        request ??= fetchSkillCatalogue();
        request.then((loaded) => {
            if (!loaded) {
                request = null;
            }

            if (active) {
                setCatalogue(loaded);
            }
        });

        return () => {
            active = false;
        };
    }, []);

    const index = useMemo(
        () => (catalogue ? buildIndex(catalogue) : null),
        [catalogue],
    );

    /** The catalogue skill a typed name means, or null for a skill matched by its words. */
    const resolve = (text: string): CatalogueSkill | null => {
        if (!index) {
            return null;
        }

        const folded = foldSkill(text);

        for (const candidate of [folded, index.core(folded)]) {
            const hit = candidate
                ? index.byKey.get(candidate.replace(/ /g, ''))
                : undefined;

            if (hit) {
                return hit;
            }
        }

        return null;
    };

    /** Up to `limit` skills whose name or alias starts with what's typed (names first). */
    const suggest = (
        text: string,
        exclude: string[],
        limit = 6,
    ): CatalogueSkill[] => {
        const needle = foldSkill(text);

        if (!index || needle === '') {
            return [];
        }

        const taken = new Set(exclude.map((skill) => skill.toLowerCase()));
        const ranked: { skill: CatalogueSkill; rank: number }[] = [];

        for (const { skill, name, aliases } of index.entries) {
            if (taken.has(skill.name.toLowerCase())) {
                continue;
            }

            const rank = name.startsWith(needle)
                ? 0
                : aliases.some((alias) => alias.startsWith(needle))
                  ? 1
                  : name.includes(needle)
                    ? 2
                    : -1;

            if (rank >= 0) {
                ranked.push({ skill, rank });
            }
        }

        return ranked
            .sort(
                (a, b) =>
                    a.rank - b.rank ||
                    a.skill.name.length - b.skill.name.length,
            )
            .slice(0, limit)
            .map(({ skill }) => skill);
    };

    return { ready: index !== null, resolve, suggest };
}

/** What {@link useSkillCatalogue} gives a form. */
export type SkillCatalogueApi = ReturnType<typeof useSkillCatalogue>;

/** What a recognised skill also accepts, in a sentence for its tooltip. */
export function describeSkill(skill: CatalogueSkill): string {
    const parts: string[] = [];

    if (skill.aliases.length > 0) {
        parts.push(`Also counts: ${skill.aliases.slice(0, 6).join(', ')}`);
    }

    if (skill.proven_by.length > 0) {
        const more = skill.proven_by_count - skill.proven_by.length;

        parts.push(
            `Proven by: ${skill.proven_by.join(', ')}${more > 0 ? ` and ${more} more` : ''}`,
        );
    }

    if (skill.related.length > 0) {
        parts.push(`Partly by: ${skill.related.join(', ')}`);
    }

    return parts.join('\n') || `Recognised as ${skill.name}.`;
}

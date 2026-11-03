import { useEffect, useState } from 'react';
import {
    fetchEscoSuggestions,
    fetchRoleSkills,
    fetchSkillReading,
} from '../api';
import type { EscoSuggestion, RoleSkills, SkillReading } from '../types';
import { foldSkill } from './use-skill-catalogue';
import type { SkillCatalogueApi } from './use-skill-catalogue';

/*
 * What the posting form learns from ESCO (ADR 0100) about the skills the
 * catalogue doesn't know. Every answer is kept for the page's life and shared
 * by every form, so a skill, a typed prefix or a title is asked about once;
 * the server keeps them too.
 */
const readings = new Map<string, SkillReading | null>();
const pendingReadings = new Map<string, Promise<SkillReading | null>>();
const suggestions = new Map<string, EscoSuggestion[]>();
const roles = new Map<string, RoleSkills | null>();

/** Too short to be worth asking about (the server agrees). */
const MIN_QUERY = 3;

/** Wait this long after the last keystroke before asking for suggestions. */
const SUGGEST_DELAY_MS = 300;

/** Ask ESCO for suggestions only when the catalogue has fewer than this of its own. */
export const ESCO_SUGGEST_BELOW = 3;

function readingOf(skill: string): Promise<SkillReading | null> {
    const key = foldSkill(skill);

    if (readings.has(key)) {
        return Promise.resolve(readings.get(key) ?? null);
    }

    let request = pendingReadings.get(key);

    if (!request) {
        request = fetchSkillReading(skill).then((reading) => {
            readings.set(key, reading);
            pendingReadings.delete(key);

            return reading;
        });
        pendingReadings.set(key, request);
    }

    return request;
}

/**
 * How ESCO reads each of these skills that the catalogue doesn't know. A
 * skill absent from the result is still being looked up; `null` means ESCO
 * has nothing by that name (or couldn't be asked), so it is matched by its
 * words. A skill with alternatives ("React or Vue") isn't looked up whole.
 */
export function useEscoReadings(
    skills: string[],
    catalogue: SkillCatalogueApi,
): Record<string, SkillReading | null> {
    const [known, setKnown] = useState<Record<string, SkillReading | null>>({});

    const wanted = catalogue.ready
        ? skills
              .filter(
                  (skill) =>
                      foldSkill(skill).length >= MIN_QUERY &&
                      !/\s+or\s+/i.test(skill) &&
                      !catalogue.resolve(skill),
              )
              .join('\n')
        : '';

    useEffect(() => {
        if (wanted === '') {
            return;
        }

        let active = true;

        for (const skill of wanted.split('\n')) {
            readingOf(skill).then((reading) => {
                if (active) {
                    setKnown((current) => ({
                        ...current,
                        [foldSkill(skill)]: reading,
                    }));
                }
            });
        }

        return () => {
            active = false;
        };
    }, [wanted]);

    return known;
}

/** The reading for one skill from {@link useEscoReadings}'s result. */
export function escoReadingFor(
    known: Record<string, SkillReading | null>,
    skill: string,
): SkillReading | null | undefined {
    const key = foldSkill(skill);

    return key in known ? known[key] : undefined;
}

/**
 * ESCO skills that start with what is typed, once the typing pauses. Asked
 * only while `enabled` (the catalogue has little to offer for it).
 */
export function useEscoSuggestions(
    draft: string,
    enabled: boolean,
): EscoSuggestion[] {
    const query = foldSkill(draft);
    const [answered, setAnswered] = useState<{
        query: string;
        items: EscoSuggestion[];
    }>({ query: '', items: [] });

    const wanted = enabled && query.length >= MIN_QUERY ? query : '';

    useEffect(() => {
        if (wanted === '' || suggestions.has(wanted)) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetchEscoSuggestions(wanted, controller.signal).then((items) => {
                if (!controller.signal.aborted) {
                    suggestions.set(wanted, items);
                    setAnswered({ query: wanted, items });
                }
            });
        }, SUGGEST_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [wanted]);

    if (wanted === '') {
        return [];
    }

    return answered.query === wanted
        ? answered.items
        : (suggestions.get(wanted) ?? []);
}

/**
 * The skills ESCO lists for a job title, asked for when HR asks (`load`).
 * `state` is `idle` until then; `none` when ESCO has no such occupation or
 * couldn't be asked.
 */
export function useRoleSkills(title: string) {
    const key = foldSkill(title);
    const [asked, setAsked] = useState<{
        key: string;
        loading: boolean;
        role: RoleSkills | null;
    }>({ key: '', loading: false, role: null });

    const load = () => {
        if (key.length < MIN_QUERY) {
            return;
        }

        if (roles.has(key)) {
            setAsked({ key, loading: false, role: roles.get(key) ?? null });

            return;
        }

        setAsked({ key, loading: true, role: null });
        fetchRoleSkills(title).then((role) => {
            roles.set(key, role);
            setAsked((current) =>
                current.key === key ? { key, loading: false, role } : current,
            );
        });
    };

    const current = asked.key === key ? asked : null;
    const state: 'idle' | 'loading' | 'found' | 'none' = !current
        ? 'idle'
        : current.loading
          ? 'loading'
          : current.role
            ? 'found'
            : 'none';

    return {
        canAsk: key.length >= MIN_QUERY,
        state,
        role: current?.role ?? null,
        load,
    };
}

/** What an ESCO reading means for a skill, in a sentence for its tooltip. */
export function describeEscoReading(reading: SkillReading): string {
    const esco = reading.esco;

    if (!esco) {
        return 'Matched by its words, in any order or form.';
    }

    if (esco.same) {
        return `ESCO: ${esco.label}\nRead as ${esco.same}, as the skill catalogue reads it.`;
    }

    const parts = [`ESCO: ${esco.label}`];

    if (esco.also.length > 0) {
        parts.push(`Also counts: ${esco.also.join(', ')}`);
    }

    if (esco.proven_by.length > 0) {
        parts.push(`Proven by: ${esco.proven_by.join(', ')}`);
    }

    if (esco.related.length > 0) {
        parts.push(`Partly by: ${esco.related.join(', ')}`);
    }

    return parts.join('\n');
}

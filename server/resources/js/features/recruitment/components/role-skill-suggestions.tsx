import { ExternalLink, Globe, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { useRoleSkills } from '../hooks/use-esco';
import type { RoleSkill } from '../types';

/** A posting takes at most this many skills in each list (StoreJobPostingRequest). */
const MAX_SKILLS = 20;

/** How many of a list are shown before "more". */
const SHOWN = 10;

/**
 * The skills ESCO lists for the posting's title (ADR 0100), offered to add:
 * its essential skills as must-haves, its optional ones as nice-to-haves.
 * Asked for only when HR asks, and never added by itself — the title is a
 * starting point, and HR knows the role.
 */
export function RoleSkillSuggestions({
    title,
    must,
    nice,
    onAddMust,
    onAddNice,
}: {
    title: string;
    must: string[];
    nice: string[];
    onAddMust: (skill: string) => void;
    onAddNice: (skill: string) => void;
}) {
    const role = useRoleSkills(title);

    if (!role.canAsk) {
        return null;
    }

    if (role.state === 'idle') {
        return (
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={role.load}
                className="-ml-2 text-muted-foreground"
            >
                <Globe className="size-3.5" />
                Suggest the skills ESCO lists for “{title.trim()}”
            </Button>
        );
    }

    if (role.state === 'loading') {
        return (
            <p className="flex items-center gap-2 py-1.5 text-xs text-muted-foreground">
                <Spinner className="size-3.5" />
                Asking ESCO about “{title.trim()}”…
            </p>
        );
    }

    if (!role.role) {
        return (
            <p className="py-1.5 text-xs text-muted-foreground">
                ESCO has no occupation called “{title.trim()}”. The skills you
                list are matched just the same.
            </p>
        );
    }

    const chosen = new Set([...must, ...nice].map((s) => s.toLowerCase()));
    const { occupation, essential, optional } = role.role;

    return (
        <div className="rounded-md border border-border bg-muted/30 p-3">
            <p className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-xs">
                <span className="font-medium">
                    ESCO lists these for{' '}
                    {occupation.exact
                        ? occupation.label.toLowerCase()
                        : `its closest occupation, ${occupation.label.toLowerCase()}`}
                </span>
                <a
                    href={occupation.url}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="inline-flex items-center gap-1 text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                >
                    View on ESCO
                    <ExternalLink className="size-3" />
                </a>
            </p>

            <SkillGroup
                label="Essential — add as must-haves"
                skills={essential}
                chosen={chosen}
                full={must.length >= MAX_SKILLS}
                onAdd={onAddMust}
            />
            <SkillGroup
                label="Optional — add as nice-to-haves"
                skills={optional}
                chosen={chosen}
                full={nice.length >= MAX_SKILLS}
                onAdd={onAddNice}
            />
        </div>
    );
}

function SkillGroup({
    label,
    skills,
    chosen,
    full,
    onAdd,
}: {
    label: string;
    skills: RoleSkill[];
    chosen: Set<string>;
    full: boolean;
    onAdd: (skill: string) => void;
}) {
    const [all, setAll] = useState(false);
    const open = skills.filter((s) => !chosen.has(s.name.toLowerCase()));

    if (open.length === 0) {
        return null;
    }

    const shown = all ? open : open.slice(0, SHOWN);

    return (
        <div className="mt-2.5">
            <p className="mb-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <div className="flex flex-wrap gap-1.5">
                {shown.map((skill) => (
                    <button
                        key={skill.name}
                        type="button"
                        disabled={full}
                        onClick={() => onAdd(skill.name)}
                        title={
                            full
                                ? 'This list is full (20 skills).'
                                : skill.catalogue
                                  ? 'A skill the catalogue knows.'
                                  : 'Matched as ESCO describes it.'
                        }
                        className={cn(
                            'inline-flex items-center gap-1 rounded-md border border-dashed border-border bg-background px-2 py-0.5 text-xs transition-colors',
                            'hover:border-solid hover:border-[#0ABFBF] hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            'disabled:cursor-not-allowed disabled:opacity-50',
                        )}
                    >
                        <Plus className="size-3" />
                        {skill.name}
                    </button>
                ))}
                {open.length > SHOWN && (
                    <button
                        type="button"
                        onClick={() => setAll(!all)}
                        className="px-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    >
                        {all ? 'Fewer' : `${open.length - SHOWN} more`}
                    </button>
                )}
            </div>
        </div>
    );
}

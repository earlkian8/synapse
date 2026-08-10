import { CircleDashed, MailCheck, ShieldCheck } from 'lucide-react';
import type { AppAccess } from '../types';

/**
 * Whether a roster line has reached a person yet (ADR 0026).
 *
 * Only `active` is coloured. `invited` and `none` are states of *waiting*, not
 * problems, and a directory where a third of the rows glow amber reads as a page
 * full of errors — so they stay in the muted register and let the eye pass over
 * them until somebody comes looking.
 */
const VARIANTS: Record<
    AppAccess,
    { label: string; hint: string; icon: typeof ShieldCheck; className: string }
> = {
    active: {
        label: 'Active',
        hint: 'Signed up and able to use the app',
        icon: ShieldCheck,
        className: 'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#067F7F]',
    },
    invited: {
        label: 'Invited',
        hint: 'Invitation sent — waiting for them to accept',
        icon: MailCheck,
        className: 'border-border bg-muted/60 text-muted-foreground',
    },
    none: {
        label: 'Not invited',
        hint: 'Nobody has been invited to this record yet',
        icon: CircleDashed,
        className: 'border-transparent bg-transparent text-muted-foreground/70',
    },
};

export function AppAccessBadge({ access }: { access: AppAccess }) {
    const variant = VARIANTS[access] ?? VARIANTS.none;
    const Icon = variant.icon;

    return (
        <span
            title={variant.hint}
            className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium ${variant.className}`}
        >
            <Icon className="size-3.5" aria-hidden />
            {variant.label}
        </span>
    );
}

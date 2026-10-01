import {
    BarChart3,
    BriefcaseBusiness,
    LifeBuoy,
    Rocket,
    Settings2,
    ShieldCheck,
    Smartphone,
    Sparkles,
    UserRound,
    UserRoundMinus,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * Each category's icon, by the name the server's catalogue gives it — the same
 * icons the sidebar uses for the matching section, so the manual and the app
 * point at the same places the same way.
 */
const CATEGORY_ICONS: Record<string, LucideIcon> = {
    rocket: Rocket,
    'user-round': UserRound,
    'briefcase-business': BriefcaseBusiness,
    users: Users,
    'user-round-minus': UserRoundMinus,
    'chart-column': BarChart3,
    'settings-2': Settings2,
    'shield-check': ShieldCheck,
    sparkles: Sparkles,
    smartphone: Smartphone,
    'life-buoy': LifeBuoy,
};

/** A category's icon, drawn. */
export function CategoryIcon({
    name,
    className,
}: {
    name: string;
    className?: string;
}) {
    const Icon = CATEGORY_ICONS[name] ?? LifeBuoy;

    return <Icon aria-hidden className={className} />;
}

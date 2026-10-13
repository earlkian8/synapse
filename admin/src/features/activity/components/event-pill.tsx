import { StatusPill } from '@/components/status-pill';
import type { Tone } from '@/components/status-pill';

/** One tone per kind of event, so a run of failed sign-ins stands out. */
const TONES: Record<string, Tone> = {
    sign_in: 'emerald',
    sign_out: 'neutral',
    sign_in_failed: 'rose',
    two_factor_failed: 'rose',
    two_factor_enrolled: 'teal',
    password_changed: 'teal',
    sessions_revoked: 'neutral',
    admin_created: 'sky',
    admin_updated: 'sky',
    admin_activated: 'emerald',
    admin_deactivated: 'amber',
    admin_password_reset: 'amber',
    admin_two_factor_reset: 'amber',
    admin_deleted: 'rose',
};

export function EventPill({ event, label }: { event: string; label: string }) {
    return <StatusPill tone={TONES[event] ?? 'neutral'}>{label}</StatusPill>;
}

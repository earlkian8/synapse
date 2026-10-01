import type { ShiftSource } from '@/features/attendance/types';

/**
 * Why a shift applies, said the way somebody would say it (ADR 0037). The
 * denormalised pointer and a dated assignment mean the same thing to a reader,
 * so they read the same.
 */
export const SHIFT_SOURCE_LABELS: Record<ShiftSource, string> = {
    roster: 'One-off override',
    assignment: 'Assigned shift',
    employee: 'Assigned shift',
    department: 'Department default',
    location: 'Work location default',
    organization: 'Company default',
    fallback: 'Default hours',
};

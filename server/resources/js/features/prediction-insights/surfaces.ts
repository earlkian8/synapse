import {
    TIER_LABELS as RISK_LABELS,
    TIER_STYLES as RISK_STYLES,
} from '@/features/attrition-risk/constants';
import {
    BAND_LABELS,
    BAND_STYLES,
} from '@/features/performance-forecast/constants';
import {
    TIER_LABELS as READINESS_LABELS,
    TIER_STYLES as READINESS_STYLES,
} from '@/features/promotion-readiness/constants';
import type { Surface } from './types';

/**
 * How each predictive surface is named and its tiers drawn when one surface shows
 * another's result (ADR 0099) — the same labels and pills as on its own page.
 */
export const SURFACES: Record<
    Surface,
    {
        name: string;
        noun: string;
        labels: Record<string, string>;
        styles: Record<string, string>;
    }
> = {
    attrition: {
        name: 'Attrition risk',
        noun: 'risk',
        labels: RISK_LABELS,
        styles: RISK_STYLES,
    },
    promotion: {
        name: 'Promotion readiness',
        noun: 'readiness',
        labels: READINESS_LABELS,
        styles: READINESS_STYLES,
    },
    performance: {
        name: 'Performance forecast',
        noun: 'forecast',
        labels: BAND_LABELS,
        styles: BAND_STYLES,
    },
};

/** The order the surfaces are listed in, wherever they sit together. */
export const SURFACE_ORDER: Surface[] = [
    'attrition',
    'promotion',
    'performance',
];

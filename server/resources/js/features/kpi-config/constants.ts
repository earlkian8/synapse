import type { ResultDisplay } from '@/features/performance/types';

/**
 * What a scorecard leads with once a framework has been scored. Shared by the
 * framework editor and the setup wizard, so a company designing its first
 * framework is offered exactly what it will be offered ever after.
 */
export const RESULT_DISPLAYS: {
    value: ResultDisplay;
    label: string;
    hint: string;
}[] = [
    {
        value: 'band',
        label: 'The rating',
        hint: 'The scorecard leads with the band — "Exceeds Expectations".',
    },
    {
        value: 'percent',
        label: 'Attainment',
        hint: 'The scorecard leads with the number — "78.4%".',
    },
    {
        value: 'points',
        label: 'Points out of 5',
        hint: 'The scorecard leads with the 1–5 index.',
    },
];

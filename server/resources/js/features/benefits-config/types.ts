import type { BenefitPlan } from '@/features/benefits/types';

export type BenefitSetupPageProps = {
    plans: BenefitPlan[];
    archived: BenefitPlan[];
    can: { manage: boolean };
};

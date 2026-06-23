import type { AwardType } from '@/features/awards/types';

export type AwardTypeSetupPageProps = {
    types: AwardType[];
    archived: AwardType[];
    can: { manage: boolean };
};

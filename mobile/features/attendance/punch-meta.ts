import type { IconName } from '@/components/ui/icon';
import { status as statusColors } from '@/theme/tokens';
import type { PunchType } from '@/types/api';

type PunchMeta = { label: string; icon: IconName; color: string };

export const PUNCH_META: Record<PunchType, PunchMeta> = {
  clock_in: { label: 'Time In', icon: 'clockIn', color: statusColors.present },
  clock_out: { label: 'Time Out', icon: 'clockOut', color: statusColors.absent },
  break_start: { label: 'Start Break', icon: 'breakStart', color: statusColors.late },
  break_end: { label: 'End Break', icon: 'breakEnd', color: statusColors.present },
};

/** Labels for the punch timeline rows. */
export const PUNCH_TIMELINE_LABEL: Record<PunchType, string> = {
  clock_in: 'Time In',
  break_start: 'Break Start',
  break_end: 'Break End',
  clock_out: 'Time Out',
};

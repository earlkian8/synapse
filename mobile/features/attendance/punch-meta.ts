import { Ionicons } from '@expo/vector-icons';

import { status as statusColors } from '@/theme/tokens';
import type { PunchType } from '@/types/api';

type PunchMeta = { label: string; icon: keyof typeof Ionicons.glyphMap; color: string };

export const PUNCH_META: Record<PunchType, PunchMeta> = {
  clock_in: { label: 'Time In', icon: 'log-in-outline', color: statusColors.present },
  clock_out: { label: 'Time Out', icon: 'log-out-outline', color: statusColors.absent },
  break_start: { label: 'Start Break', icon: 'cafe-outline', color: statusColors.late },
  break_end: { label: 'End Break', icon: 'play-outline', color: statusColors.present },
};

/** Labels for the punch timeline rows. */
export const PUNCH_TIMELINE_LABEL: Record<PunchType, string> = {
  clock_in: 'Time In',
  break_start: 'Break Start',
  break_end: 'Break End',
  clock_out: 'Time Out',
};

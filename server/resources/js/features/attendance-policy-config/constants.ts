import { formatDuration } from '@/features/attendance/constants';
import type {
    GeofenceMode,
    MissingClockOutAction,
    OvertimeBasis,
    PolicySettings,
    PunchSource,
    RoundingMode,
    RoundingTarget,
    RoundingUnit,
    SampleDay,
} from './types';

export const ROUNDING_MODE_OPTIONS: { value: RoundingMode; label: string }[] = [
    { value: 'none', label: 'Exact' },
    { value: 'nearest', label: 'Nearest' },
    { value: 'up', label: 'Up' },
    { value: 'down', label: 'Down' },
];

export const ROUNDING_UNITS: RoundingUnit[] = [5, 10, 15, 30];

export const ROUNDING_TARGET_OPTIONS: {
    value: RoundingTarget;
    label: string;
}[] = [
    { value: 'in', label: 'Clock-in' },
    { value: 'out', label: 'Clock-out' },
    { value: 'both', label: 'Both' },
];

export const OVERTIME_BASIS_OPTIONS: {
    value: OvertimeBasis;
    label: string;
    hint: string;
}[] = [
    { value: 'daily', label: 'Daily', hint: 'Beyond the hours of each day.' },
    { value: 'weekly', label: 'Weekly', hint: 'Beyond the hours of the week.' },
    {
        value: 'daily_and_weekly',
        label: 'Both',
        hint: 'Daily first; the week counts only what was not.',
    },
    { value: 'none', label: 'None', hint: 'Nothing is overtime.' },
];

export const MISSING_CLOCK_OUT_OPTIONS: {
    value: MissingClockOutAction;
    label: string;
}[] = [
    { value: 'flag', label: 'Leave it open for HR' },
    { value: 'auto_close_at_shift_end', label: 'Close it at the shift’s end' },
    {
        value: 'auto_close_after_minutes',
        label: 'Close it a while after the shift',
    },
];

export const GEOFENCE_OPTIONS: { value: GeofenceMode; label: string }[] = [
    { value: 'off', label: 'Don’t check where' },
    { value: 'flag', label: 'Accept, but flag outside the site' },
    { value: 'block', label: 'Refuse outside the site' },
];

export const SOURCE_LABELS: Record<PunchSource, string> = {
    web: 'Web',
    mobile: 'Mobile app',
    kiosk: 'Kiosk',
    biometric: 'Biometric device',
    manual: 'Entered by HR',
};

/** Every way a punch can arrive, in the order they are offered. */
export const PUNCH_SOURCES = Object.keys(SOURCE_LABELS) as PunchSource[];

/** The day the worked example opens on — the one the plan describes. */
export const DEFAULT_SAMPLE: SampleDay = {
    day: 'working',
    shift_start: '08:00',
    shift_end: '17:00',
    required_minutes: 480,
    grace_minutes: 0,
    time_in: '08:07',
    time_out: '17:42',
    break_start: '',
    break_end: '',
};

/** "1h 30m" for a minute count; "off" for an unset threshold. */
export function minutesLabel(minutes: number | null | undefined): string {
    return minutes === null || minutes === undefined
        ? 'off'
        : formatDuration(minutes);
}

/**
 * What each group of settings amounts to, in a line — shown in a collapsed
 * section's header so a closed group still says what it does.
 */
export function groupSummaries(
    s: PolicySettings,
): Record<keyof PolicySettings, string> {
    const lateness = !s.lateness.enabled
        ? 'Nobody is marked late'
        : [
              s.lateness.grace_mode === 'monthly_allowance'
                  ? `${formatDuration(s.lateness.monthly_grace_minutes)} of grace a month`
                  : s.lateness.grace_minutes === null
                    ? 'Each schedule’s grace'
                    : `${formatDuration(s.lateness.grace_minutes)} grace a day`,
              s.lateness.half_day_after_minutes !== null &&
                  `half day past ${formatDuration(s.lateness.half_day_after_minutes)}`,
              s.lateness.absent_after_minutes !== null &&
                  `absent past ${formatDuration(s.lateness.absent_after_minutes)}`,
          ]
              .filter(Boolean)
              .join(', ');

    const undertime = [
        s.undertime.basis === 'hours'
            ? 'Short by the hours worked'
            : 'Short by leaving before the shift ends',
        s.undertime.half_day_below_minutes !== null &&
            `half day under ${formatDuration(s.undertime.half_day_below_minutes)}`,
    ]
        .filter(Boolean)
        .join(', ');

    const rounding =
        s.rounding.mode === 'none'
            ? 'Exact times'
            : `${s.rounding.mode === 'nearest' ? 'Nearest' : s.rounding.mode === 'up' ? 'Up to the' : 'Down to the'} ${s.rounding.unit} minutes, ${
                  s.rounding.apply_to === 'both'
                      ? 'in and out'
                      : s.rounding.apply_to === 'in'
                        ? 'clock-in only'
                        : 'clock-out only'
              }`;

    const breaks =
        s.breaks.auto_deduct_minutes > 0
            ? `${formatDuration(s.breaks.auto_deduct_minutes)} unpaid after ${formatDuration(s.breaks.auto_deduct_after_worked_minutes)} if none is punched`
            : s.breaks.paid_break_minutes > 0
              ? `${formatDuration(s.breaks.paid_break_minutes)} of a break is paid`
              : 'Breaks count as punched';

    const overtime =
        s.overtime.basis === 'none'
            ? 'No overtime'
            : [
                  s.overtime.basis !== 'weekly' &&
                      (s.overtime.daily_after_minutes === null
                          ? 'After the day’s hours'
                          : `After ${formatDuration(s.overtime.daily_after_minutes)} a day`),
                  s.overtime.basis !== 'daily' &&
                      `after ${formatDuration(s.overtime.weekly_after_minutes)} a week`,
                  s.overtime.requires_approval && 'needs approval',
              ]
                  .filter(Boolean)
                  .join(', ');

    return {
        lateness,
        undertime,
        rounding,
        breaks,
        overtime,
        night: s.night.enabled
            ? `${s.night.start}–${s.night.end}`
            : 'No night differential',
        punch_windows: `Clock in up to ${formatDuration(s.punch_windows.early_clock_in_minutes)} early; shifts up to ${formatDuration(s.punch_windows.max_shift_span_minutes)}`,
        missing_clock_out:
            MISSING_CLOCK_OUT_OPTIONS.find(
                (option) => option.value === s.missing_clock_out.action,
            )?.label ?? '',
        capture: `${s.capture.allowed_sources.length} ways to punch${s.capture.selfie_required ? ', selfie required' : ''}`,
    };
}

/** The handful of rules a policy row leads with. */
export function policyHeadline(s: PolicySettings): string[] {
    const summaries = groupSummaries(s);

    return [
        summaries.overtime,
        s.breaks.auto_deduct_minutes > 0 ? summaries.breaks : null,
        s.night.enabled ? `Night ${summaries.night}` : null,
        s.rounding.mode !== 'none' ? summaries.rounding : null,
        !s.lateness.enabled ? summaries.lateness : null,
    ].filter((line): line is string => line !== null);
}

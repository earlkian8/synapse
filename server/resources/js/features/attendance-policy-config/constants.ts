import { formatDuration } from '@/features/attendance/constants';
import type {
    PolicySettings,
    PunchSource,
    RoundingTarget,
    RoundingUnit,
    SampleDay,
} from './types';

export const ROUNDING_UNITS: RoundingUnit[] = [5, 10, 15, 30];

/** Read into "Round [clock-ins and clock-outs]." */
export const ROUNDING_TARGET_OPTIONS: {
    value: RoundingTarget;
    label: string;
}[] = [
    { value: 'both', label: 'clock-ins and clock-outs' },
    { value: 'in', label: 'clock-ins only' },
    { value: 'out', label: 'clock-outs only' },
];

export const SOURCE_LABELS: Record<PunchSource, string> = {
    mobile: 'With the mobile app',
    qr: 'At a QR station',
    kiosk: 'At a kiosk',
    web: 'On the web',
    manual: 'Entered by HR',
};

/** What each way of clocking in is, for someone who has never set one up. */
export const SOURCE_DESCRIPTIONS: Record<PunchSource, string> = {
    mobile: 'On their own phone, with the SYNAPSE app.',
    qr: 'By scanning the changing code on a screen at the site.',
    kiosk: 'With their employee number and PIN, on a shared tablet.',
    web: 'From a browser, usually on a work computer.',
    manual: 'HR types the time in for them, with a reason.',
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

/** "2 hours", "45 minutes", "1h 30m" — a length of time as a sentence reads it. */
function spoken(minutes: number): string {
    if (minutes > 0 && minutes % 60 === 0) {
        const hours = minutes / 60;

        return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    return minutes < 60 ? `${minutes} min` : formatDuration(minutes);
}

/**
 * What each topic of the editor amounts to, in a line — shown in its rail so the
 * whole policy reads without opening anything.
 */
export function topicSummaries(
    s: PolicySettings,
): Record<
    'late' | 'short' | 'breaks' | 'overtime' | 'clocking' | 'fine',
    string
> {
    const late = !s.lateness.enabled
        ? 'Nobody is marked late'
        : [
              s.lateness.grace_mode === 'monthly_allowance'
                  ? `${spoken(s.lateness.monthly_grace_minutes)} let go a month`
                  : s.lateness.grace_minutes === null
                    ? 'Each schedule’s grace'
                    : s.lateness.grace_minutes === 0
                      ? 'Late from the first minute'
                      : `First ${spoken(s.lateness.grace_minutes)} let go`,
              s.lateness.half_day_after_minutes !== null &&
                  `half day past ${spoken(s.lateness.half_day_after_minutes)}`,
              s.lateness.absent_after_minutes !== null &&
                  `absent past ${spoken(s.lateness.absent_after_minutes)}`,
          ]
              .filter(Boolean)
              .join(', ');

    const short = [
        s.undertime.basis === 'hours'
            ? 'Short on hours worked'
            : 'Short when leaving early',
        s.undertime.half_day_below_minutes !== null &&
            `half day under ${spoken(s.undertime.half_day_below_minutes)}`,
        s.undertime.minimum_minutes_for_present !== null &&
            `absent under ${spoken(s.undertime.minimum_minutes_for_present)}`,
    ]
        .filter(Boolean)
        .join(', ');

    const breaks =
        s.breaks.auto_deduct_minutes > 0
            ? `${spoken(s.breaks.auto_deduct_minutes)} off when no break is punched`
            : s.breaks.paid_break_minutes > 0
              ? `First ${spoken(s.breaks.paid_break_minutes)} of a break paid`
              : 'Breaks as punched, unpaid';

    const overtime =
        s.overtime.basis === 'none'
            ? 'No overtime'
            : [
                  s.overtime.basis !== 'weekly' &&
                      (s.overtime.daily_after_minutes === null
                          ? 'After the shift’s hours'
                          : `After ${spoken(s.overtime.daily_after_minutes)} a day`),
                  s.overtime.basis !== 'daily' &&
                      `after ${spoken(s.overtime.weekly_after_minutes)} a week`,
                  s.overtime.requires_approval && 'approved first',
                  s.night.enabled && 'night hours apart',
              ]
                  .filter(Boolean)
                  .join(', ');

    const ways = s.capture.allowed_sources.filter(
        (source) => source !== 'manual',
    );
    const clocking = [
        ways.length === 0
            ? 'Only HR enters times'
            : ways
                  .map(
                      (source) =>
                          ({
                              mobile: 'App',
                              qr: 'QR',
                              kiosk: 'Kiosk',
                              web: 'Web',
                          })[source as 'mobile'],
                  )
                  .join(', '),
        s.capture.selfie_required && 'photo',
        s.capture.geofence === 'flag' && 'flagged off site',
        s.capture.geofence === 'block' && 'on site only',
    ]
        .filter(Boolean)
        .join(', ');

    const fine =
        s.rounding.mode === 'none'
            ? 'Exact times'
            : `Rounded ${s.rounding.mode === 'nearest' ? 'to the nearest' : s.rounding.mode} ${s.rounding.unit} min`;

    return {
        late,
        short,
        breaks,
        overtime,
        clocking,
        fine,
    };
}

/** The handful of rules a policy row leads with. */
export function policyHeadline(s: PolicySettings): string[] {
    const topics = topicSummaries(s);

    return [
        topics.overtime,
        s.breaks.auto_deduct_minutes > 0 ? topics.breaks : null,
        s.rounding.mode !== 'none' ? topics.fine : null,
        !s.lateness.enabled ? topics.late : null,
    ].filter((line): line is string => line !== null);
}

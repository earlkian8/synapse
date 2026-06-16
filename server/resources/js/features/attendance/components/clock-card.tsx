import { Camera, Clock, MapPin, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { punch } from '../api';
import { formatDuration, PUNCH_META } from '../constants';
import { getCurrentPosition, useNow } from '../hooks/use-clock';
import type { AttendanceRecord, MySchedule, PunchType } from '../types';
import { AttendanceStatusBadge } from './attendance-status-badge';

type Props = {
    today: AttendanceRecord;
    nextExpected: PunchType | null;
    allowed: PunchType[];
    schedule: MySchedule | null;
    canClock: boolean;
};

/** Accent classes for the big primary punch button, per punch type. */
const PRIMARY_STYLES: Record<PunchType, string> = {
    clock_in: 'bg-emerald-600 hover:bg-emerald-600/90',
    clock_out: 'bg-rose-600 hover:bg-rose-600/90',
    break_start: 'bg-amber-600 hover:bg-amber-600/90',
    break_end: 'bg-sky-600 hover:bg-sky-600/90',
};

export function ClockCard({
    today,
    nextExpected,
    allowed,
    schedule,
    canClock,
}: Props) {
    const now = useNow();
    const [processing, setProcessing] = useState<PunchType | null>(null);
    const [selfie, setSelfie] = useState<File | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);

    const secondary: PunchType | null = allowed.includes('break_start')
        ? 'break_start'
        : null;

    const doPunch = async (type: PunchType) => {
        setProcessing(type);
        const coords = await getCurrentPosition();

        punch(type, {
            coords,
            photo: selfie,
            onFinish: () => {
                setProcessing(null);
                setSelfie(null);

                if (fileRef.current) {
                    fileRef.current.value = '';
                }
            },
        });
    };

    const time = now.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });
    const date = now.toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });

    return (
        <div className="overflow-hidden rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-[#0F2044] to-[#0a9ca3] text-white shadow-lg dark:border-sidebar-border">
            <div className="space-y-5 p-6">
                {/* Live clock */}
                <div>
                    <div className="flex items-center gap-2 text-xs text-white/70">
                        <Clock className="size-3.5" />
                        {date}
                    </div>
                    <p className="mt-1 text-5xl font-semibold tracking-tight tabular-nums">
                        {time}
                    </p>
                </div>

                {/* Status + worked so far */}
                <div className="flex flex-wrap items-center gap-2">
                    <AttendanceStatusBadge
                        status={today.status}
                        className="border-white/20 bg-white/15 text-white"
                    />
                    {today.worked_minutes > 0 && (
                        <span className="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-white/90">
                            {formatDuration(today.worked_minutes)} worked
                        </span>
                    )}
                    {schedule?.start_time && schedule?.end_time && (
                        <span className="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-white/90">
                            {schedule.name} · {schedule.start_time}–
                            {schedule.end_time}
                        </span>
                    )}
                </div>

                {!canClock ? (
                    <p className="rounded-lg bg-white/10 px-3 py-3 text-sm text-white/80">
                        You don't have permission to clock in or out.
                    </p>
                ) : nextExpected === null ? (
                    <p className="rounded-lg bg-white/10 px-3 py-3 text-center text-sm text-white/80">
                        Your shift is complete for today. 🎉
                    </p>
                ) : (
                    <div className="space-y-3">
                        {/* Optional selfie attach */}
                        <input
                            ref={fileRef}
                            type="file"
                            accept="image/*"
                            capture="user"
                            className="hidden"
                            onChange={(event) =>
                                setSelfie(event.target.files?.[0] ?? null)
                            }
                        />
                        <div className="flex items-center justify-between text-xs text-white/70">
                            <span className="inline-flex items-center gap-1.5">
                                <MapPin className="size-3.5" />
                                Location captured on punch
                            </span>
                            {selfie ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSelfie(null);

                                        if (fileRef.current) {
                                            fileRef.current.value = '';
                                        }
                                    }}
                                    className="inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-white"
                                >
                                    <Camera className="size-3" />
                                    Selfie ready
                                    <X className="size-3" />
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => fileRef.current?.click()}
                                    className="inline-flex items-center gap-1 hover:text-white"
                                >
                                    <Camera className="size-3.5" />
                                    Add selfie
                                </button>
                            )}
                        </div>

                        <div className="flex gap-2">
                            <PrimaryButton
                                type={nextExpected}
                                processing={processing === nextExpected}
                                disabled={processing !== null}
                                onClick={() => doPunch(nextExpected)}
                            />
                            {secondary && (
                                <Button
                                    variant="outline"
                                    className="h-12 border-white/30 bg-white/10 text-white hover:bg-white/20 hover:text-white"
                                    disabled={processing !== null}
                                    onClick={() => doPunch(secondary)}
                                >
                                    {PUNCH_META[secondary].label}
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function PrimaryButton({
    type,
    processing,
    disabled,
    onClick,
}: {
    type: PunchType;
    processing: boolean;
    disabled: boolean;
    onClick: () => void;
}) {
    const meta = PUNCH_META[type];
    const Icon = meta.icon;

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={cn(
                'flex h-12 flex-1 items-center justify-center gap-2 rounded-xl text-sm font-semibold text-white shadow-md transition-colors disabled:opacity-70',
                PRIMARY_STYLES[type],
            )}
        >
            {processing ? <Spinner /> : <Icon className="size-5" />}
            {meta.label}
        </button>
    );
}

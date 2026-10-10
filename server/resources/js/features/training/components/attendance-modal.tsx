import { ClipboardCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { saveAttendance } from '../api';
import {
    ATTENDANCE_LABELS,
    ATTENDANCE_ORDER,
    ATTENDANCE_STYLES,
    formatDate,
    formatSessionTime,
} from '../constants';
import type {
    AttendanceStatus,
    TrainingEnrollment,
    TrainingSession,
} from '../types';

/**
 * Take attendance at one session (ADR 0074): everyone holding a seat, each
 * marked present, late, absent or excused. Pressing a mark again clears it.
 * Present and late count as attended; excused leaves the session out of the
 * person's rate.
 */
export function AttendanceModal({
    session,
    enrollments,
    onOpenChange,
}: {
    session: TrainingSession | null;
    /** The roster; only people holding a seat are listed. */
    enrollments: TrainingEnrollment[];
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Modal open={session !== null} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <ClipboardCheck />
                        </ModalIcon>
                    }
                    title="Take attendance"
                    description={
                        session
                            ? `${session.title ? `${session.title} · ` : ''}${formatDate(session.session_date)}, ${formatSessionTime(session.starts_at, session.ends_at)}`
                            : ''
                    }
                />
                {session && (
                    <Sheet
                        key={session.id}
                        session={session}
                        enrollments={enrollments}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Sheet({
    session,
    enrollments,
    onDone,
}: {
    session: TrainingSession;
    enrollments: TrainingEnrollment[];
    onDone: () => void;
}) {
    // The marks already taken, by enrollment.
    const taken = useMemo(
        () =>
            new Map(
                (session.marks ?? []).map((m) => [m.enrollment_id, m.status]),
            ),
        [session],
    );

    const seated = useMemo(
        () =>
            enrollments
                .filter(
                    (e) =>
                        e.status === 'enrolled' ||
                        e.status === 'completed' ||
                        taken.has(e.id),
                )
                .sort((a, b) =>
                    (a.employee?.full_name ?? '').localeCompare(
                        b.employee?.full_name ?? '',
                    ),
                ),
        [enrollments, taken],
    );

    const [marks, setMarks] = useState<Record<number, AttendanceStatus | null>>(
        () =>
            Object.fromEntries(
                seated.map((e) => [e.id, taken.get(e.id) ?? null]),
            ),
    );
    const [processing, setProcessing] = useState(false);

    const set = (id: number, status: AttendanceStatus) =>
        setMarks((prev) => ({
            ...prev,
            [id]: prev[id] === status ? null : status,
        }));

    const unmarked = seated.filter((e) => !marks[e.id]).length;

    const markRest = () =>
        setMarks((prev) =>
            Object.fromEntries(
                seated.map((e) => [e.id, prev[e.id] ?? 'present']),
            ),
        );

    // Only what changed is sent, so a row left alone never clears a mark.
    const changed = seated.filter(
        (e) => marks[e.id] !== (taken.get(e.id) ?? null),
    );

    const submit = () =>
        saveAttendance(
            session.id,
            changed.map((e) => ({ enrollment_id: e.id, status: marks[e.id] })),
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
            },
        );

    return (
        <>
            <ModalBody className="p-0 sm:p-0">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-2.5 sm:px-6">
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {seated.length - unmarked} of {seated.length} marked
                    </p>
                    {unmarked > 0 && (
                        <Button variant="outline" size="sm" onClick={markRest}>
                            Mark the rest present
                        </Button>
                    )}
                </div>

                {seated.length === 0 ? (
                    <p className="px-6 py-10 text-center text-sm text-muted-foreground">
                        Nobody holds a seat on this program yet.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {seated.map((enrollment) => {
                            const name =
                                enrollment.employee?.full_name ?? 'Unknown';

                            return (
                                <li
                                    key={enrollment.id}
                                    className="flex flex-col gap-2 px-5 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                                >
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <PersonAvatar
                                            name={name}
                                            initials={
                                                enrollment.employee?.initials ??
                                                '?'
                                            }
                                            photo={enrollment.employee?.photo}
                                            className="size-7"
                                            fallbackClassName="text-[10px]"
                                        />
                                        <span className="truncate text-sm font-medium">
                                            {name}
                                        </span>
                                        {enrollment.status === 'dropped' && (
                                            <span className="text-xs text-muted-foreground">
                                                (dropped)
                                            </span>
                                        )}
                                    </div>
                                    <div
                                        role="group"
                                        aria-label={`Attendance for ${name}`}
                                        className="flex shrink-0 gap-1"
                                    >
                                        {ATTENDANCE_ORDER.map((status) => {
                                            const active =
                                                marks[enrollment.id] === status;

                                            return (
                                                <button
                                                    key={status}
                                                    type="button"
                                                    aria-pressed={active}
                                                    disabled={
                                                        enrollment.status ===
                                                        'dropped'
                                                    }
                                                    onClick={() =>
                                                        set(
                                                            enrollment.id,
                                                            status,
                                                        )
                                                    }
                                                    className={cn(
                                                        'min-h-8 rounded-md border px-2.5 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                                                        active
                                                            ? ATTENDANCE_STYLES[
                                                                  status
                                                              ]
                                                            : 'border-border text-muted-foreground hover:text-foreground',
                                                    )}
                                                >
                                                    {ATTENDANCE_LABELS[status]}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </ModalBody>

            <ModalFooter>
                <Button
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    onClick={submit}
                    disabled={processing || changed.length === 0}
                >
                    {processing && <Spinner />}
                    Save attendance
                </Button>
            </ModalFooter>
        </>
    );
}

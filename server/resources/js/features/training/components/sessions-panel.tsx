import {
    CalendarPlus,
    CalendarX2,
    ClipboardCheck,
    MapPin,
    Pencil,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { deleteSession } from '../api';
import { formatSessionTime, todayIso } from '../constants';
import type {
    ProgramAttendance,
    TrainingEnrollment,
    TrainingSession,
} from '../types';
import { AttendanceModal } from './attendance-modal';
import { SessionFormModal } from './session-form-modal';

/**
 * A program's sessions (ADR 0074): when and where each sits, and the attendance
 * taken at it. A manager schedules, edits and cancels sessions, and takes
 * attendance from a session's day on — a held session's row opens its sheet.
 */
export function SessionsPanel({
    programHashid,
    window,
    sessions,
    enrollments,
    attendance,
    canManage,
}: {
    programHashid: string;
    window: { start: string | null; end: string | null };
    sessions: TrainingSession[];
    enrollments: TrainingEnrollment[];
    attendance: ProgramAttendance;
    canManage: boolean;
}) {
    const [form, setForm] = useState<{
        open: boolean;
        session: TrainingSession | null;
    }>({ open: false, session: null });
    const [taking, setTaking] = useState<TrainingSession | null>(null);
    const [cancelling, setCancelling] = useState<TrainingSession | null>(null);
    const [processing, setProcessing] = useState(false);

    const seated = enrollments.filter(
        (e) => e.status === 'enrolled' || e.status === 'completed',
    ).length;
    const today = todayIso();

    const cancel = () => {
        if (!cancelling) {
            return;
        }

        deleteSession(cancelling.id, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setCancelling(null);
            },
        });
    };

    const schedule = () => setForm({ open: true, session: null });

    return (
        <>
            <TableCard
                title="Sessions"
                count={sessions.length}
                description={
                    sessions.length === 0
                        ? 'When the program meets. Attendance is taken per session.'
                        : `${attendance.held} of ${attendance.sessions} held${attendance.rate !== null ? ` · ${attendance.rate}% attendance` : ''}`
                }
                actions={
                    canManage && (
                        <Button size="sm" onClick={schedule}>
                            <CalendarPlus className="size-4" />
                            Schedule session
                        </Button>
                    )
                }
            >
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Day</TableHead>
                            <TableHead>Time</TableHead>
                            <TableHead className="hidden md:table-cell">
                                Session
                            </TableHead>
                            <TableHead className="text-right">
                                Attendance
                            </TableHead>
                            {canManage && <TableHead className="w-10" />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {sessions.length === 0 && (
                            <EmptyTableRow
                                colSpan={canManage ? 5 : 4}
                                icon={CalendarX2}
                                title="No sessions yet"
                                description={
                                    canManage
                                        ? 'Schedule when the program meets — repeat a session to add one per day.'
                                        : 'No sessions have been scheduled.'
                                }
                                action={
                                    canManage ? (
                                        <Button size="sm" onClick={schedule}>
                                            <CalendarPlus className="size-4" />
                                            Schedule the first session
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )}

                        {sessions.map((session) => {
                            const day = new Date(
                                `${session.session_date}T00:00:00`,
                            );
                            const isToday = session.session_date === today;
                            const opens = rowOpens(() =>
                                session.is_held
                                    ? setTaking(session)
                                    : setForm({ open: true, session }),
                            );

                            return (
                                <TableRow
                                    key={session.id}
                                    {...(canManage ? opens : {})}
                                    className={cn(
                                        canManage && opens.className,
                                        isToday && 'bg-[#0ABFBF]/5',
                                    )}
                                >
                                    <TableCell>
                                        <div className="flex items-baseline gap-2">
                                            <span className="w-7 text-lg leading-none font-semibold tabular-nums">
                                                {day.getDate()}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {isToday
                                                    ? 'Today'
                                                    : `${day.toLocaleDateString(undefined, { weekday: 'short' })}, ${day.toLocaleDateString(
                                                          undefined,
                                                          {
                                                              month: 'short',
                                                              year:
                                                                  day.getFullYear() ===
                                                                  new Date().getFullYear()
                                                                      ? undefined
                                                                      : 'numeric',
                                                          },
                                                      )}`}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-sm whitespace-nowrap tabular-nums">
                                        {formatSessionTime(
                                            session.starts_at,
                                            session.ends_at,
                                        )}
                                    </TableCell>
                                    <TableCell className="hidden md:table-cell">
                                        <p className="max-w-72 truncate text-sm">
                                            {session.title ?? (
                                                <span className="text-muted-foreground">
                                                    Session
                                                </span>
                                            )}
                                        </p>
                                        {session.location && (
                                            <p className="flex max-w-72 items-center gap-1 truncate text-xs text-muted-foreground">
                                                <MapPin className="size-3 shrink-0" />
                                                {session.location}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-sm">
                                        <Tally
                                            session={session}
                                            seated={seated}
                                        />
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <RowMenuTrigger label="Session actions" />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent
                                                    align="end"
                                                    className="w-48"
                                                >
                                                    <DropdownMenuItem
                                                        disabled={
                                                            !session.is_held
                                                        }
                                                        onSelect={() =>
                                                            setTaking(session)
                                                        }
                                                    >
                                                        <ClipboardCheck className="size-4" />
                                                        Take attendance
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setForm({
                                                                open: true,
                                                                session,
                                                            })
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                        Edit
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            setCancelling(
                                                                session,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                        Cancel session
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    )}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </DataTable>
            </TableCard>

            <SessionFormModal
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                programHashid={programHashid}
                window={window}
                session={form.session}
            />

            <AttendanceModal
                session={taking}
                enrollments={enrollments}
                onOpenChange={(open) => !open && setTaking(null)}
            />

            <ConfirmDialog
                open={cancelling !== null}
                onOpenChange={(open) => !open && setCancelling(null)}
                title="Cancel this session?"
                description={
                    cancelling?.tally &&
                    Object.values(cancelling.tally).some((n) => n > 0)
                        ? 'The attendance taken at it is removed too, and everyone’s rate is worked out without it.'
                        : 'It is removed from the program and from everyone’s calendar.'
                }
                confirmLabel="Cancel session"
                destructive
                processing={processing}
                onConfirm={cancel}
            />
        </>
    );
}

/** What was marked at a session, or why nothing is yet. */
function Tally({
    session,
    seated,
}: {
    session: TrainingSession;
    seated: number;
}) {
    if (!session.is_held) {
        return <span className="text-muted-foreground">Upcoming</span>;
    }

    const tally = session.tally;
    const marked = tally
        ? tally.present + tally.late + tally.absent + tally.excused
        : 0;

    if (!tally || marked === 0) {
        return (
            <span className="text-amber-600 dark:text-amber-400">
                Not taken
            </span>
        );
    }

    return (
        <span className="tabular-nums">
            <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                {tally.present + tally.late}
            </span>
            <span className="text-muted-foreground"> of {seated} came</span>
            {tally.absent > 0 && (
                <span className="ml-1.5 text-xs text-rose-600 dark:text-rose-400">
                    {tally.absent} absent
                </span>
            )}
        </span>
    );
}

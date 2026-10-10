import { CalendarPlus } from 'lucide-react';
import { useState } from 'react';
import { FormField } from '@/components/form-field';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { saveSession } from '../api';
import { TEXTAREA } from '../constants';
import type { TrainingSession } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    programHashid: string;
    /** The program's dates; a session falls inside them. */
    window: { start: string | null; end: string | null };
    /** Editing this session; omitted to schedule a new one. */
    session?: TrainingSession | null;
};

/**
 * Schedule a program's session — a day, local start and end times, an
 * optional title, place and notes — or change one (ADR 0074). A new session
 * can repeat on every day, or every weekday, up to a date, so a five-day
 * course is one entry.
 */
export function SessionFormModal({
    open,
    onOpenChange,
    programHashid,
    window,
    session,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <CalendarPlus />
                        </ModalIcon>
                    }
                    title={session ? 'Edit session' : 'Schedule a session'}
                    description={
                        session
                            ? 'Times are on your company’s clock.'
                            : 'Times are on your company’s clock. Repeat it to add one per day.'
                    }
                />
                {open && (
                    <SessionForm
                        key={session?.id ?? 'new'}
                        programHashid={programHashid}
                        window={window}
                        session={session ?? null}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function SessionForm({
    programHashid,
    window,
    session,
    onDone,
}: {
    programHashid: string;
    window: { start: string | null; end: string | null };
    session: TrainingSession | null;
    onDone: () => void;
}) {
    const editing = session !== null;
    const [title, setTitle] = useState(session?.title ?? '');
    const [date, setDate] = useState(
        session?.session_date ?? window.start ?? '',
    );
    const [startsAt, setStartsAt] = useState(session?.starts_at ?? '09:00');
    const [endsAt, setEndsAt] = useState(session?.ends_at ?? '17:00');
    const [location, setLocation] = useState(session?.location ?? '');
    const [notes, setNotes] = useState(session?.notes ?? '');
    const [repeat, setRepeat] = useState(false);
    const [until, setUntil] = useState(window.end ?? '');
    const [weekdaysOnly, setWeekdaysOnly] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = () =>
        saveSession(
            editing ? { sessionId: session.id } : { programHashid },
            {
                title: title.trim() || null,
                session_date: date,
                starts_at: startsAt,
                ends_at: endsAt,
                location: location.trim() || null,
                notes: notes.trim() || null,
                ...(editing
                    ? {}
                    : {
                          repeat_until: repeat && until ? until : null,
                          weekdays_only: repeat && weekdaysOnly,
                      }),
            },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
                onError: setErrors,
            },
        );

    const bounds = {
        min: window.start ?? undefined,
        max: window.end ?? undefined,
    };

    return (
        <>
            <ModalBody className="space-y-4">
                <FormField
                    label="Title"
                    hint="Optional, e.g. “Day 1: Foundations”."
                    error={errors.title}
                >
                    <Input
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        maxLength={120}
                    />
                </FormField>

                <FormField
                    label={repeat ? 'First day' : 'Day'}
                    required
                    error={errors.session_date}
                >
                    <Input
                        type="date"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                        {...bounds}
                        className="sm:w-48"
                    />
                </FormField>

                <div className="grid grid-cols-2 gap-3 sm:w-96">
                    <FormField label="Starts" required error={errors.starts_at}>
                        <Input
                            type="time"
                            value={startsAt}
                            onChange={(e) => setStartsAt(e.target.value)}
                        />
                    </FormField>
                    <FormField label="Ends" required error={errors.ends_at}>
                        <Input
                            type="time"
                            value={endsAt}
                            onChange={(e) => setEndsAt(e.target.value)}
                        />
                    </FormField>
                </div>

                {!editing && (
                    <div className="space-y-3 rounded-lg border border-border p-3">
                        <label className="flex items-center justify-between gap-4">
                            <span className="text-sm font-medium">
                                Repeat daily
                            </span>
                            <Switch
                                checked={repeat}
                                onCheckedChange={setRepeat}
                                aria-label="Repeat daily"
                            />
                        </label>
                        {repeat && (
                            <>
                                <FormField
                                    label="Until"
                                    error={errors.repeat_until}
                                >
                                    <Input
                                        type="date"
                                        value={until}
                                        onChange={(e) =>
                                            setUntil(e.target.value)
                                        }
                                        min={date || bounds.min}
                                        max={bounds.max}
                                        className="sm:w-48"
                                    />
                                </FormField>
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={weekdaysOnly}
                                        onCheckedChange={(checked) =>
                                            setWeekdaysOnly(checked === true)
                                        }
                                    />
                                    Weekdays only
                                </label>
                            </>
                        )}
                    </div>
                )}

                <FormField label="Where" error={errors.location}>
                    <Input
                        value={location}
                        onChange={(e) => setLocation(e.target.value)}
                        placeholder="e.g. Training Room B, or a meeting link"
                        maxLength={160}
                    />
                </FormField>

                <FormField label="Notes" error={errors.notes}>
                    <textarea
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        rows={2}
                        maxLength={1000}
                        placeholder="What to bring, what to read first…"
                        className={TEXTAREA}
                    />
                </FormField>
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
                    disabled={processing || !date || !startsAt || !endsAt}
                >
                    {processing && <Spinner />}
                    {editing
                        ? 'Save'
                        : repeat
                          ? 'Schedule sessions'
                          : 'Schedule'}
                </Button>
            </ModalFooter>
        </>
    );
}

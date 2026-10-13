import {
    CalendarClock,
    Fingerprint,
    Globe,
    Monitor,
    Target,
    UserRound,
} from 'lucide-react';
import {
    Fact,
    FactGrid,
    RecordDialog,
    RecordSection,
} from '@/components/record-dialog';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { ActivityEntry } from '../api';
import { EventPill } from './event-pill';

/** One log entry in full: who, what, on what, and from where. */
export function ActivityDialog({
    entry,
    open,
    onClose,
}: {
    entry: ActivityEntry | null;
    open: boolean;
    onClose: () => void;
}) {
    return (
        <RecordDialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Log entry"
            description="Who did what, on what, and from where."
            size="md"
            header={
                entry ? (
                    <div className="space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <EventPill
                                event={entry.event}
                                label={entry.event_label}
                            />
                            <span className="text-xs text-muted-foreground">
                                {formatDateTime(entry.created_at)}
                            </span>
                        </div>
                        <p className="text-base leading-snug font-semibold">
                            {entry.description}
                        </p>
                    </div>
                ) : undefined
            }
        >
            {entry && (
                <div className="space-y-4">
                    <FactGrid columns={2}>
                        <Fact
                            icon={UserRound}
                            label="Admin"
                            value={
                                entry.actor?.name ??
                                (entry.actor
                                    ? 'No admin has this email'
                                    : 'Server console')
                            }
                        />
                        <Fact
                            icon={Fingerprint}
                            label="Email"
                            value={entry.actor?.email}
                            title={entry.actor?.email ?? undefined}
                        />
                        <Fact
                            icon={Target}
                            label="Target"
                            value={
                                entry.subject_label ??
                                (entry.subject_type
                                    ? `${entry.subject_type} #${entry.subject_id}`
                                    : null)
                            }
                            title={entry.subject_label ?? undefined}
                        />
                        <Fact
                            icon={CalendarClock}
                            label="When"
                            value={formatRelative(entry.created_at)}
                            title={formatDateTime(entry.created_at)}
                        />
                        <Fact
                            icon={Globe}
                            label="IP address"
                            value={
                                entry.ip_address ? (
                                    <span className="font-mono text-xs">
                                        {entry.ip_address}
                                    </span>
                                ) : null
                            }
                            wide
                        />
                        <Fact
                            icon={Monitor}
                            label="Browser"
                            value={
                                entry.user_agent ? (
                                    <span className="text-xs font-normal">
                                        {entry.user_agent}
                                    </span>
                                ) : null
                            }
                            wide
                        />
                    </FactGrid>

                    {entry.properties &&
                        Object.keys(entry.properties).length > 0 && (
                            <RecordSection title="Details">
                                <pre className="max-h-48 overflow-auto rounded-lg border border-sidebar-border/70 bg-muted/40 px-3 py-2 font-mono text-xs leading-relaxed">
                                    {JSON.stringify(entry.properties, null, 2)}
                                </pre>
                            </RecordSection>
                        )}
                </div>
            )}
        </RecordDialog>
    );
}

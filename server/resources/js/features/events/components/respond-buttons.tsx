import { router } from '@inertiajs/react';
import { Check, CircleHelp, X } from 'lucide-react';
import { useId, useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import {
    ANSWER_ACTIVE_STYLES,
    ANSWER_LABELS,
    ANSWER_ORDER,
} from '../constants';
import { eventRoutes } from '../routes';
import type { InviteeAnswer, MyInvitation } from '../types';

const ICONS: Record<InviteeAnswer, typeof Check> = {
    accepted: Check,
    tentative: CircleHelp,
    declined: X,
};

/**
 * An invitee's own answer, one tap: Going, Maybe or Not going. The chosen one
 * fills with its colour. On a repeating event, "Also for later dates" carries
 * the answer to every later date they are invited to.
 */
export function RespondButtons({ invitation }: { invitation: MyInvitation }) {
    const [following, setFollowing] = useState(false);
    const [sending, setSending] = useState<InviteeAnswer | null>(null);
    const checkboxId = useId();
    const { event } = invitation;
    const closed = event.status === 'past';

    const answer = (response: InviteeAnswer) =>
        router.post(
            eventRoutes.meRespond(event.hashid),
            { response, scope: following ? 'following' : 'this' },
            {
                preserveScroll: true,
                onStart: () => setSending(response),
                onFinish: () => setSending(null),
            },
        );

    return (
        <div className="flex flex-col gap-2">
            <div
                role="group"
                aria-label={`Your answer to ${event.title}`}
                className="inline-flex w-full rounded-lg border border-border bg-muted/40 p-0.5 sm:w-auto"
            >
                {ANSWER_ORDER.map((response) => {
                    const Icon = ICONS[response];
                    const chosen = invitation.response === response;

                    return (
                        <button
                            key={response}
                            type="button"
                            disabled={closed || sending !== null}
                            aria-pressed={chosen}
                            onClick={() => answer(response)}
                            className={cn(
                                'inline-flex flex-1 items-center justify-center gap-1.5 rounded-md border border-transparent px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed sm:flex-none',
                                chosen
                                    ? ANSWER_ACTIVE_STYLES[response]
                                    : 'text-muted-foreground hover:bg-background hover:text-foreground',
                                sending === response && 'opacity-70',
                                closed && !chosen && 'opacity-50',
                            )}
                        >
                            <Icon className="size-3.5" aria-hidden />
                            {ANSWER_LABELS[response]}
                        </button>
                    );
                })}
            </div>

            {event.series && !closed && (
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Checkbox
                        id={checkboxId}
                        checked={following}
                        onCheckedChange={(checked) =>
                            setFollowing(checked === true)
                        }
                    />
                    <label htmlFor={checkboxId} className="cursor-pointer">
                        Also for later dates
                    </label>
                </div>
            )}
        </div>
    );
}

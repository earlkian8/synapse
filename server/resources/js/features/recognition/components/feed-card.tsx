import { ArrowRight, Award, Trash2 } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { ago } from '../constants';
import type { FeedItem } from '../types';

/**
 * One moment on the wall — a row of the wall's list. Kudos lead with the words
 * a colleague wrote; an award leads with its badge, in the award's own colour.
 */
export function FeedCard({
    item,
    onRemove,
}: {
    item: FeedItem;
    onRemove?: (item: FeedItem) => void;
}) {
    const tint = item.award_type?.color ?? '#0ABFBF';

    return (
        <article className="flex gap-3.5 px-4 py-4">
            {item.kind === 'kudos' && item.from ? (
                <PersonAvatar
                    name={item.from.name}
                    initials={item.from.initials}
                    photo={item.from.photo}
                    className="size-10"
                />
            ) : (
                <span
                    className="flex size-10 shrink-0 items-center justify-center rounded-full"
                    style={{ backgroundColor: `${tint}22`, color: tint }}
                    aria-hidden
                >
                    <Award className="size-5" />
                </span>
            )}

            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <div className="flex items-start justify-between gap-3">
                    <p className="text-sm leading-snug">
                        {item.kind === 'kudos' ? (
                            <>
                                <span className="font-semibold">
                                    {item.from?.name ?? 'A former colleague'}
                                </span>
                                <ArrowRight
                                    className="mx-1 inline size-3.5 text-muted-foreground"
                                    aria-label="to"
                                />
                                <span className="font-semibold">
                                    {item.to?.name ?? 'a former colleague'}
                                </span>
                            </>
                        ) : (
                            <>
                                <span className="font-semibold">
                                    {item.to?.name ?? 'A former colleague'}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    received
                                </span>{' '}
                                <span className="font-semibold">
                                    {item.award_type?.name ?? 'an award'}
                                </span>
                            </>
                        )}
                        <span className="ml-2 text-xs text-muted-foreground">
                            {ago(item.at)}
                        </span>
                    </p>
                    <div className="flex shrink-0 items-center gap-1">
                        {item.points > 0 && (
                            <span className="rounded-full bg-[#0ABFBF]/12 px-2 py-0.5 text-xs font-semibold text-[#08767c] tabular-nums dark:text-[#0ABFBF]">
                                +{item.points}
                            </span>
                        )}
                        {item.can_remove && onRemove && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7 text-muted-foreground hover:text-destructive"
                                aria-label="Take these kudos down"
                                onClick={() => onRemove(item)}
                            >
                                <Trash2 className="size-3.5" />
                            </Button>
                        )}
                    </div>
                </div>

                {item.message && (
                    <p
                        className={
                            item.kind === 'kudos'
                                ? 'text-[15px] leading-relaxed text-foreground'
                                : 'text-sm leading-relaxed text-muted-foreground'
                        }
                    >
                        {item.message}
                    </p>
                )}
            </div>
        </article>
    );
}

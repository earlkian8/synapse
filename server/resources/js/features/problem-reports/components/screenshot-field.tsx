import { ImagePlus, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { SCREENSHOTS } from '../constants';

export type Screenshot = { id: string; file: File; preview: string };

/** Turn picked, dropped or pasted files into screenshots, saying why any were left out. */
export function takeScreenshots(
    files: File[],
    current: Screenshot[],
): { added: Screenshot[]; problem: string | null } {
    const room = SCREENSHOTS.max - current.length;
    const images = files.filter((file) =>
        (SCREENSHOTS.types as readonly string[]).includes(file.type),
    );
    const fitting = images.filter((file) => file.size <= SCREENSHOTS.maxBytes);
    const added = fitting.slice(0, Math.max(0, room)).map((file) => ({
        id: `${file.name}-${file.size}-${file.lastModified}-${Math.random()}`,
        file,
        preview: URL.createObjectURL(file),
    }));

    let problem: string | null = null;

    if (images.length < files.length) {
        problem = 'Only PNG, JPEG and WebP images can be attached.';
    } else if (fitting.length < images.length) {
        problem = 'Each screenshot must be under 8 MB.';
    } else if (fitting.length > room) {
        problem = `You can attach up to ${SCREENSHOTS.max} screenshots.`;
    }

    return { added, problem };
}

/**
 * Up to three screenshots: chosen, dropped on the box, or pasted anywhere in
 * the dialog (the dialog hands pasted files in through `onAdd`). Each shows
 * as a thumbnail that can be taken off again. The previews are object URLs the
 * form makes as files arrive, and gives back when they leave.
 */
export function ScreenshotField({
    value,
    onAdd,
    onRemove,
    error,
    disabled,
}: {
    value: Screenshot[];
    onAdd: (files: File[]) => void;
    onRemove: (id: string) => void;
    error?: string;
    disabled?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [over, setOver] = useState(false);
    const full = value.length >= SCREENSHOTS.max;

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-2">
                {value.map((shot) => (
                    <figure
                        key={shot.id}
                        className="group relative size-20 overflow-hidden rounded-lg border border-sidebar-border/70 bg-muted dark:border-sidebar-border"
                    >
                        <img
                            src={shot.preview}
                            alt={shot.file.name}
                            className="size-full object-cover object-top"
                        />
                        <button
                            type="button"
                            onClick={() => onRemove(shot.id)}
                            disabled={disabled}
                            aria-label={`Remove ${shot.file.name}`}
                            className="absolute top-1 right-1 flex size-6 items-center justify-center rounded-full bg-black/60 text-white opacity-90 transition-opacity hover:bg-black/75 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none sm:opacity-0 sm:group-hover:opacity-100"
                        >
                            <X className="size-3.5" />
                        </button>
                    </figure>
                ))}

                {!full && (
                    <button
                        type="button"
                        disabled={disabled}
                        onClick={() => input.current?.click()}
                        onDragOver={(event) => {
                            event.preventDefault();
                            setOver(true);
                        }}
                        onDragLeave={() => setOver(false)}
                        onDrop={(event) => {
                            event.preventDefault();
                            setOver(false);
                            onAdd(Array.from(event.dataTransfer.files));
                        }}
                        className={cn(
                            'flex h-20 min-w-20 flex-1 items-center gap-3 rounded-lg border border-dashed px-4 text-left transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50',
                            over
                                ? 'border-[#0ABFBF] bg-[#0ABFBF]/8'
                                : 'border-input hover:border-[#0ABFBF]/60 hover:bg-muted/40',
                            error && 'border-destructive',
                        )}
                    >
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0A9E9E] dark:text-[#0ABFBF]">
                            <ImagePlus className="size-[18px]" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">
                                {value.length === 0
                                    ? 'Add a screenshot'
                                    : 'Add another'}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Choose, drop, or paste with{' '}
                                <kbd className="font-sans">Ctrl</kbd>+
                                <kbd className="font-sans">V</kbd>
                                <span className="hidden sm:inline">
                                    {' '}
                                    · {SCREENSHOTS.max - value.length} left
                                </span>
                            </span>
                        </span>
                    </button>
                )}
            </div>

            <input
                ref={input}
                type="file"
                accept={SCREENSHOTS.types.join(',')}
                multiple
                hidden
                onChange={(event) => {
                    onAdd(Array.from(event.target.files ?? []));
                    event.target.value = '';
                }}
            />
        </div>
    );
}

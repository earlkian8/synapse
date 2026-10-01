import { ArrowUp, Paperclip, Square, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

const ACCEPT = '.pdf,.png,.jpg,.jpeg,.webp,.txt';
const MAX_FILES = 8;

export function Composer({
    input,
    files,
    busy,
    onInput,
    onAddFiles,
    onRemoveFile,
    onSend,
    onStop,
}: {
    input: string;
    files: File[];
    busy: boolean;
    onInput: (value: string) => void;
    onAddFiles: (files: File[]) => void;
    onRemoveFile: (index: number) => void;
    onSend: () => void;
    onStop: () => void;
}) {
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    // Auto-grow the textarea up to a cap.
    useEffect(() => {
        const el = textareaRef.current;

        if (!el) {
            return;
        }

        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 140)}px`;
    }, [input]);

    const onKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            onSend();
        }
    };

    const onPaste = (event: React.ClipboardEvent) => {
        const pasted = Array.from(event.clipboardData.files ?? []);

        if (pasted.length > 0) {
            event.preventDefault();
            onAddFiles(pasted);
        }
    };

    const onDrop = (event: React.DragEvent) => {
        event.preventDefault();
        setDragging(false);
        const dropped = Array.from(event.dataTransfer.files ?? []);

        if (dropped.length > 0) {
            onAddFiles(dropped);
        }
    };

    const canSend = input.trim() !== '' || files.length > 0;

    return (
        <div
            onDragOver={(e) => {
                e.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={onDrop}
            className={cn(
                'relative border-t border-border bg-card px-3 py-2.5',
                dragging && 'bg-[#0ABFBF]/5',
            )}
        >
            {dragging && (
                <div className="pointer-events-none absolute inset-1.5 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-[#0ABFBF]/60 bg-card/80 text-xs font-medium text-[#0ABFBF]">
                    Drop files to attach
                </div>
            )}

            {files.length > 0 && (
                <div className="mb-2 flex flex-wrap gap-1.5">
                    {files.map((file, index) => (
                        <div
                            key={`${file.name}-${index}`}
                            className="flex max-w-[180px] items-center gap-1.5 rounded-lg border border-border bg-muted/50 px-2 py-1 text-xs"
                        >
                            <Paperclip className="size-3 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1 truncate">
                                {file.name}
                            </span>
                            <button
                                type="button"
                                onClick={() => onRemoveFile(index)}
                                aria-label={`Remove ${file.name}`}
                                className="text-muted-foreground hover:text-destructive"
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            <div className="flex items-end gap-1.5 rounded-2xl border border-input bg-background px-1.5 py-1.5 focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30">
                <button
                    type="button"
                    onClick={() => fileInput.current?.click()}
                    aria-label="Attach files"
                    className="flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <Paperclip className="size-4" />
                </button>
                <input
                    ref={fileInput}
                    type="file"
                    multiple
                    accept={ACCEPT}
                    className="hidden"
                    onChange={(event) => {
                        onAddFiles(Array.from(event.target.files ?? []));

                        if (event.target) {
                            event.target.value = '';
                        }
                    }}
                />

                <textarea
                    ref={textareaRef}
                    value={input}
                    onChange={(event) => onInput(event.target.value)}
                    onKeyDown={onKeyDown}
                    onPaste={onPaste}
                    rows={1}
                    placeholder="Message the assistant…"
                    className="max-h-[140px] min-h-8 flex-1 resize-none bg-transparent px-1 py-1.5 text-sm outline-none"
                />

                {busy ? (
                    <button
                        type="button"
                        onClick={onStop}
                        aria-label="Stop"
                        className="flex size-8 shrink-0 items-center justify-center rounded-xl bg-muted text-foreground transition-colors hover:bg-muted/80"
                    >
                        <Square className="size-3.5 fill-current" />
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={onSend}
                        disabled={!canSend}
                        aria-label="Send"
                        className="flex size-8 shrink-0 items-center justify-center rounded-xl bg-[#0F2044] text-white transition-all hover:bg-[#0F2044]/90 disabled:opacity-40"
                    >
                        <ArrowUp className="size-4" />
                    </button>
                )}
            </div>

            <p className="mt-1 px-1 text-[10px] text-muted-foreground">
                {files.length >= MAX_FILES
                    ? 'Attachment limit reached (8).'
                    : 'Enter to send · Shift+Enter for a new line'}
            </p>
        </div>
    );
}

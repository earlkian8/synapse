import {
    ArrowUp,
    FileText,
    Image as ImageIcon,
    Paperclip,
    Square,
    X,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import type { AssistantMode } from '../types';
import { ModeMenu } from './mode-menu';

const ACCEPT = '.pdf,.png,.jpg,.jpeg,.webp,.txt';

/** How many files one message may carry (the server's limit too). */
export const MAX_FILES = 8;

const isImage = (file: File) => file.type.startsWith('image/');

/**
 * Where a message is written: the text on top, and a row beneath it to attach
 * files, choose when the assistant asks first, and send. Files can also be
 * pasted, or dropped anywhere on the panel (`assistant.tsx` handles the drop).
 */
export function Composer({
    ref,
    input,
    files,
    busy,
    mode,
    onModeChange,
    onInput,
    onAddFiles,
    onRemoveFile,
    onSend,
    onStop,
}: {
    ref: React.RefObject<HTMLTextAreaElement | null>;
    input: string;
    files: File[];
    busy: boolean;
    mode: AssistantMode;
    onModeChange: (mode: AssistantMode) => void;
    onInput: (value: string) => void;
    onAddFiles: (files: File[]) => void;
    onRemoveFile: (index: number) => void;
    onSend: () => void;
    onStop: () => void;
}) {
    const fileInput = useRef<HTMLInputElement>(null);

    // Auto-grow the textarea up to a cap.
    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
    }, [input, ref]);

    const onKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing
        ) {
            event.preventDefault();

            if (!busy) {
                onSend();
            }
        }
    };

    const onPaste = (event: React.ClipboardEvent) => {
        const pasted = Array.from(event.clipboardData.files ?? []);

        if (pasted.length > 0) {
            event.preventDefault();
            onAddFiles(pasted);
        }
    };

    const canSend = input.trim() !== '' || files.length > 0;
    const full = files.length >= MAX_FILES;

    return (
        <div className="shrink-0 px-3 pt-1 pb-3">
            <div className="rounded-xl border border-input bg-background shadow-xs transition-[border-color,box-shadow] focus-within:border-assistant-signal-text/60 focus-within:ring-3 focus-within:ring-assistant-signal/15">
                {files.length > 0 && (
                    <ul
                        aria-label="Attached files"
                        className="flex flex-wrap gap-1.5 px-2.5 pt-2.5"
                    >
                        {files.map((file, index) => {
                            const Icon = isImage(file) ? ImageIcon : FileText;

                            return (
                                <li
                                    key={`${file.name}-${index}`}
                                    className="flex max-w-[200px] items-center gap-1.5 rounded-md bg-muted py-1 pr-1 pl-2 text-xs"
                                >
                                    <Icon className="size-3.5 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1 truncate">
                                        {file.name}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => onRemoveFile(index)}
                                        aria-label={`Remove ${file.name}`}
                                        className="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
                                    >
                                        <X className="size-3" />
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}

                <label htmlFor="assistant-composer" className="sr-only">
                    Message the assistant
                </label>
                <textarea
                    id="assistant-composer"
                    ref={ref}
                    value={input}
                    onChange={(event) => onInput(event.target.value)}
                    onKeyDown={onKeyDown}
                    onPaste={onPaste}
                    rows={1}
                    placeholder="Ask a question or describe a task"
                    aria-describedby="assistant-composer-hint"
                    className="block max-h-[200px] min-h-11 w-full resize-none bg-transparent px-3 pt-2.5 pb-1 text-sm leading-6 outline-none placeholder:text-muted-foreground"
                />

                <div className="flex items-center gap-2 px-1.5 pb-1.5">
                    <button
                        type="button"
                        onClick={() => fileInput.current?.click()}
                        disabled={full}
                        aria-label="Attach files"
                        title="Attach files"
                        className="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                    >
                        <Paperclip className="size-4" />
                    </button>
                    <ModeMenu mode={mode} onChange={onModeChange} />
                    <input
                        ref={fileInput}
                        type="file"
                        multiple
                        accept={ACCEPT}
                        className="hidden"
                        onChange={(event) => {
                            onAddFiles(Array.from(event.target.files ?? []));
                            event.target.value = '';
                        }}
                    />

                    <p
                        id="assistant-composer-hint"
                        className={cn(
                            'min-w-0 flex-1 truncate text-[11px] text-muted-foreground',
                            !full && 'hidden sm:block',
                        )}
                    >
                        {full
                            ? `That's the limit of ${MAX_FILES} files.`
                            : 'Enter to send, Shift+Enter for a new line'}
                    </p>

                    {busy ? (
                        <button
                            type="button"
                            onClick={onStop}
                            className="ml-auto flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-input px-2.5 text-xs font-medium text-foreground transition-colors hover:bg-muted"
                        >
                            <Square className="size-3 fill-current" />
                            Stop
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={onSend}
                            disabled={!canSend}
                            aria-label="Send"
                            className="ml-auto flex size-8 shrink-0 items-center justify-center rounded-lg bg-assistant-ink text-white transition-opacity hover:opacity-90 disabled:bg-muted disabled:text-muted-foreground"
                        >
                            <ArrowUp className="size-4" />
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

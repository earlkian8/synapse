import { Delete } from 'lucide-react';
import { cn } from '@/lib/utils';

const DIGITS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

const KEY =
    'flex h-[clamp(3.6rem,8.6vh,5rem)] items-center justify-center rounded-2xl border border-(--k-rule) bg-white text-[1.85rem] font-semibold text-(--k-ink) tabular-nums shadow-[inset_0_-3px_0_rgb(15_32_68/0.07)] transition-[background-color,transform] select-none active:translate-y-px active:bg-[#EAF4F4] focus-visible:ring-4 focus-visible:ring-(--k-signal)/45 focus-visible:outline-none disabled:opacity-40';

/**
 * Big keys for a finger in a work glove: the ten digits, delete, and the step's
 * own key (Next). Real buttons, so a kiosk with a keyboard or a switch can use
 * them too.
 */
export function Keypad({
    onDigit,
    onErase,
    onSubmit,
    submitLabel = 'Next',
    submitDisabled = false,
    disabled = false,
}: {
    onDigit: (digit: string) => void;
    onErase: () => void;
    onSubmit?: () => void;
    submitLabel?: string;
    submitDisabled?: boolean;
    disabled?: boolean;
}) {
    return (
        <div className="grid grid-cols-3 gap-2.5 sm:gap-3">
            {DIGITS.map((digit) => (
                <button
                    key={digit}
                    type="button"
                    disabled={disabled}
                    onClick={() => onDigit(digit)}
                    className={KEY}
                >
                    {digit}
                </button>
            ))}
            <button
                type="button"
                disabled={disabled}
                onClick={onErase}
                aria-label="Delete"
                className={cn(KEY, 'text-(--k-muted)')}
            >
                <Delete className="size-7" />
            </button>
            <button
                type="button"
                disabled={disabled}
                onClick={() => onDigit('0')}
                className={KEY}
            >
                0
            </button>
            {onSubmit ? (
                <button
                    type="button"
                    disabled={disabled || submitDisabled}
                    onClick={onSubmit}
                    className={cn(
                        KEY,
                        'border-(--k-ink) bg-(--k-ink) text-lg text-white shadow-none active:bg-(--k-ground-raised)',
                    )}
                >
                    {submitLabel}
                </button>
            ) : (
                <span aria-hidden />
            )}
        </div>
    );
}

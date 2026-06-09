import * as React from 'react';

import { cn } from '@/lib/utils';

type SwitchProps = Omit<
    React.ComponentProps<'button'>,
    'onChange' | 'value'
> & {
    checked?: boolean;
    onCheckedChange?: (checked: boolean) => void;
};

function Switch({
    className,
    checked = false,
    onCheckedChange,
    disabled,
    ...props
}: SwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            data-state={checked ? 'checked' : 'unchecked'}
            disabled={disabled}
            onClick={() => onCheckedChange?.(!checked)}
            className={cn(
                'peer inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border border-transparent transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-[#0ABFBF]' : 'bg-input',
                className,
            )}
            {...props}
        >
            <span
                className={cn(
                    'pointer-events-none block size-4 rounded-full bg-white shadow-sm ring-0 transition-transform',
                    checked ? 'translate-x-[18px]' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}

export { Switch };

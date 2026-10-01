import { Eye, EyeOff } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    icon: Icon,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & {
    ref?: Ref<HTMLInputElement>;
    /** Optional leading icon — used on the brand-teal auth screens. */
    icon?: LucideIcon;
}) {
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className="relative">
            {Icon && (
                <Icon className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground/70 transition-colors peer-focus-visible:text-[#0ABFBF]" />
            )}
            <Input
                type={showPassword ? 'text' : 'password'}
                className={cn(
                    'peer pr-10',
                    Icon &&
                        'pl-9 focus-visible:border-[#0ABFBF] focus-visible:ring-[#0ABFBF]/30',
                    className,
                )}
                ref={ref}
                {...props}
            />
            <button
                type="button"
                onClick={() => setShowPassword((prev) => !prev)}
                className="absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                aria-label={showPassword ? 'Hide password' : 'Show password'}
                tabIndex={-1}
            >
                {showPassword ? (
                    <EyeOff className="size-4" />
                ) : (
                    <Eye className="size-4" />
                )}
            </button>
        </div>
    );
}

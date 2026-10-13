import { Check } from 'lucide-react';
import { PASSWORD_RULES } from '@/lib/password-rules';
import { cn } from '@/lib/utils';

export default function PasswordRequirements({
    password,
    className,
}: {
    password: string;
    className?: string;
}) {
    return (
        <ul
            className={cn('grid grid-cols-1 gap-1 sm:grid-cols-2', className)}
            aria-label="Password requirements"
        >
            {PASSWORD_RULES.map((rule) => {
                const met = password.length > 0 && rule.test(password);

                return (
                    <li
                        key={rule.key}
                        className={cn(
                            'flex items-center gap-1.5 text-xs transition-colors',
                            met
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-muted-foreground',
                        )}
                    >
                        <span
                            className={cn(
                                'flex size-3.5 shrink-0 items-center justify-center rounded-full border transition-colors',
                                met
                                    ? 'border-emerald-500 bg-emerald-500 text-white'
                                    : 'border-muted-foreground/30',
                            )}
                        >
                            {met && (
                                <Check className="size-2.5" strokeWidth={3} />
                            )}
                        </span>
                        {rule.label}
                        <span className="sr-only">
                            {met ? '(done)' : '(not yet)'}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

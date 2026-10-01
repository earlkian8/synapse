import { Check } from 'lucide-react';
import { useMemo } from 'react';
import { parsePasswordRules } from '@/lib/password-rules';
import { cn } from '@/lib/utils';

type Props = {
    rules: string | undefined;
    password: string;
    className?: string;
};

/** A live checklist against the backend's actual password policy — see lib/password-rules.ts. */
export default function PasswordRequirements({
    rules,
    password,
    className,
}: Props) {
    const requirements = useMemo(() => parsePasswordRules(rules), [rules]);

    if (requirements.length === 0) {
        return null;
    }

    return (
        <ul className={cn('grid grid-cols-1 gap-1 sm:grid-cols-2', className)}>
            {requirements.map((requirement) => {
                const met = password.length > 0 && requirement.test(password);

                return (
                    <li
                        key={requirement.key}
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
                        {requirement.label}
                    </li>
                );
            })}
        </ul>
    );
}

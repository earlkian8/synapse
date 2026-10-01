import type { LucideIcon } from 'lucide-react';
import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Input> & { icon: LucideIcon };

/**
 * A text input with a leading icon, focus-tinted brand teal — the field
 * treatment established by the workspace picker (pages/workspaces.tsx) and
 * carried into the rest of the pre-app auth screens.
 */
export default function IconInput({ icon: Icon, className, ...props }: Props) {
    return (
        <div className="relative">
            <Icon className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground/70 transition-colors peer-focus-visible:text-[#0ABFBF]" />
            <Input
                className={cn(
                    'peer pl-9 focus-visible:border-[#0ABFBF] focus-visible:ring-[#0ABFBF]/30',
                    className,
                )}
                {...props}
            />
        </div>
    );
}

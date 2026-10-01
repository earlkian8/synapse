import { useForm } from '@inertiajs/react';
import { Megaphone, ShieldCheck, User, Users } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { LEVEL_OPTIONS } from '../constants';
import { notificationRoutes } from '../routes';
import type { Audiences, NotificationLevel } from '../types';

type Props = {
    audiences: Audiences;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type AudienceKind = 'all' | 'role' | 'user';

const AUDIENCE_OPTIONS: {
    value: AudienceKind;
    label: string;
    description: string;
    icon: typeof Users;
}[] = [
    {
        value: 'all',
        label: 'Everyone',
        description: 'All active users',
        icon: Users,
    },
    {
        value: 'role',
        label: 'A role',
        description: 'Everyone with a role',
        icon: ShieldCheck,
    },
    {
        value: 'user',
        label: 'One person',
        description: 'A specific user',
        icon: User,
    },
];

export function NotificationComposeSheet({
    audiences,
    open,
    onOpenChange,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle className="flex items-center gap-2">
                        <Megaphone className="size-4 text-[#0ABFBF]" />
                        Send a notification
                    </SheetTitle>
                    <SheetDescription>
                        Compose a message and choose who receives it. Delivery
                        respects each person's channel preferences.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <ComposeBody
                        audiences={audiences}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function ComposeBody({
    audiences,
    onDone,
}: {
    audiences: Audiences;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        audience: 'all' as AudienceKind,
        role_id: '' as string,
        user_id: '' as string,
        title: '',
        body: '',
        url: '',
        level: 'info' as NotificationLevel,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(notificationRoutes.store, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-7 px-6 py-6">
                {/* Audience */}
                <section className="space-y-3">
                    <div>
                        <h3 className="text-sm font-semibold">Audience</h3>
                        <p className="text-xs text-muted-foreground">
                            Who should receive this notification?
                        </p>
                    </div>

                    <div className="grid grid-cols-3 gap-2">
                        {AUDIENCE_OPTIONS.map((option) => {
                            const Icon = option.icon;
                            const active = data.audience === option.value;

                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() =>
                                        setData('audience', option.value)
                                    }
                                    className={cn(
                                        'flex flex-col items-start gap-1.5 rounded-lg border p-3 text-left transition-colors',
                                        active
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/5 ring-1 ring-[#0ABFBF]'
                                            : 'border-border hover:bg-accent',
                                    )}
                                >
                                    <Icon
                                        className={cn(
                                            'size-4',
                                            active
                                                ? 'text-[#0ABFBF]'
                                                : 'text-muted-foreground',
                                        )}
                                    />
                                    <span className="text-[13px] font-medium">
                                        {option.label}
                                    </span>
                                    <span className="text-[11px] text-muted-foreground">
                                        {option.description}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    {data.audience === 'role' && (
                        <div>
                            <Label htmlFor="role_id">Role</Label>
                            <Select
                                value={data.role_id}
                                onValueChange={(value) =>
                                    setData('role_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="role_id"
                                    className="mt-1.5 w-full"
                                >
                                    <SelectValue placeholder="Choose a role" />
                                </SelectTrigger>
                                <SelectContent>
                                    {audiences?.roles.map((role) => (
                                        <SelectItem
                                            key={role.id}
                                            value={String(role.id)}
                                        >
                                            {role.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={errors.role_id}
                                className="mt-1.5"
                            />
                        </div>
                    )}

                    {data.audience === 'user' && (
                        <div>
                            <Label htmlFor="user_id">Person</Label>
                            <Select
                                value={data.user_id}
                                onValueChange={(value) =>
                                    setData('user_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="user_id"
                                    className="mt-1.5 w-full"
                                >
                                    <SelectValue placeholder="Choose a person" />
                                </SelectTrigger>
                                <SelectContent>
                                    {audiences?.users.map((user) => (
                                        <SelectItem
                                            key={user.id}
                                            value={String(user.id)}
                                        >
                                            {user.name} · {user.email}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={errors.user_id}
                                className="mt-1.5"
                            />
                        </div>
                    )}
                </section>

                {/* Message */}
                <section className="space-y-4">
                    <div>
                        <h3 className="text-sm font-semibold">Message</h3>
                        <p className="text-xs text-muted-foreground">
                            What do you want to say?
                        </p>
                    </div>

                    <div>
                        <Label htmlFor="title">
                            Title
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <Input
                            id="title"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="e.g. System maintenance tonight"
                            maxLength={120}
                            className="mt-1.5"
                            autoFocus
                            required
                        />
                        <InputError message={errors.title} className="mt-1.5" />
                    </div>

                    <div>
                        <Label htmlFor="body">
                            Message
                            <span className="ml-0.5 text-destructive">*</span>
                        </Label>
                        <textarea
                            id="body"
                            value={data.body}
                            onChange={(e) => setData('body', e.target.value)}
                            placeholder="Write the details here…"
                            maxLength={1000}
                            rows={4}
                            className="mt-1.5 flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50"
                            required
                        />
                        <InputError message={errors.body} className="mt-1.5" />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="level">Importance</Label>
                            <Select
                                value={data.level}
                                onValueChange={(value) =>
                                    setData('level', value as NotificationLevel)
                                }
                            >
                                <SelectTrigger
                                    id="level"
                                    className="mt-1.5 w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {LEVEL_OPTIONS.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor="url">
                                Link
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    optional
                                </span>
                            </Label>
                            <Input
                                id="url"
                                value={data.url}
                                onChange={(e) => setData('url', e.target.value)}
                                placeholder="/leave/requests/42"
                                className="mt-1.5 font-mono text-xs"
                            />
                            <InputError
                                message={errors.url}
                                className="mt-1.5"
                            />
                        </div>
                    </div>
                </section>
            </div>

            <SheetFooter className="border-t border-border px-6 py-4">
                <div className="flex w-full items-center justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onDone}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        Send notification
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

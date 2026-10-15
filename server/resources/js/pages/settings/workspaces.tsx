import { Head, Link, router } from '@inertiajs/react';
import { Building2, Info, LogOut, Plus } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { CreateWorkspaceDialog } from '@/features/workspaces/components/create-workspace-dialog';
import { LeaveWorkspaceDialog } from '@/features/workspaces/components/leave-workspace-dialog';
import { workspaceRoutes } from '@/features/workspaces/routes';
import type { MyWorkspace } from '@/features/workspaces/types';

/**
 * Settings → Workspaces (ADR 0089): every company this person belongs to, with
 * their role there — and the two things that are theirs to do about it:
 * start another one, or leave one. Leaving is refused while they are the only
 * HR Manager of a company other people still work in; the row says why and
 * where to fix it, rather than showing a dead button.
 */
export default function Workspaces({
    workspaces,
}: {
    workspaces: MyWorkspace[];
}) {
    const [creating, setCreating] = useState(false);
    const [leaving, setLeaving] = useState<MyWorkspace | null>(null);
    const [leaveOpen, setLeaveOpen] = useState(false);

    const askLeave = (workspace: MyWorkspace) => {
        setLeaving(workspace);
        setLeaveOpen(true);
    };

    return (
        <>
            <Head title="Workspaces" />

            <h1 className="sr-only">Workspaces</h1>

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Workspaces"
                        description="The companies you belong to. Each has its own people, records and roles."
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setCreating(true)}
                    >
                        <Plus className="size-4" />
                        Create workspace
                    </Button>
                </div>

                {workspaces.length === 0 ? (
                    <div className="rounded-xl border border-dashed px-6 py-10 text-center">
                        <Building2 className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            You are not in a workspace yet
                        </p>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                            Create one for your company, or ask your employer to
                            invite you to theirs.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {workspaces.map((workspace) => (
                            <WorkspaceRow
                                key={workspace.id}
                                workspace={workspace}
                                onLeave={() => askLeave(workspace)}
                            />
                        ))}
                    </ul>
                )}
            </div>

            <CreateWorkspaceDialog open={creating} onOpenChange={setCreating} />

            <LeaveWorkspaceDialog
                workspace={leaving}
                open={leaveOpen}
                onOpenChange={setLeaveOpen}
            />
        </>
    );
}

function WorkspaceRow({
    workspace,
    onLeave,
}: {
    workspace: MyWorkspace;
    onLeave: () => void;
}) {
    const leaving = workspace.leaving;

    return (
        <li className="px-4 py-3.5">
            <div className="flex items-center gap-3">
                <Mark workspace={workspace} />

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="truncate text-sm font-medium">
                            {workspace.name}
                        </span>
                        {workspace.is_active && (
                            <span className="shrink-0 rounded-full bg-[#0ABFBF]/15 px-2 py-0.5 text-[11px] font-medium text-[#078a8a] dark:text-[#0ABFBF]">
                                Current
                            </span>
                        )}
                    </div>
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {workspace.role ?? 'Member'}
                        {workspace.joined_on && (
                            <> · Joined {formatJoined(workspace.joined_on)}</>
                        )}
                    </p>
                </div>

                {leaving?.allowed && (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={onLeave}
                    >
                        <LogOut className="size-4" />
                        Leave
                    </Button>
                )}
            </div>

            {leaving && !leaving.allowed && leaving.reason && (
                <div className="mt-3 flex gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-xs text-amber-900 sm:ml-12 dark:bg-amber-500/10 dark:text-amber-200">
                    <Info className="mt-px size-3.5 shrink-0" />
                    <div className="space-y-1.5">
                        <p>{leaving.reason}</p>
                        {workspace.is_active ? (
                            <Link
                                href="/system/users"
                                className="font-medium underline underline-offset-2"
                            >
                                Open User Management
                            </Link>
                        ) : (
                            <button
                                type="button"
                                className="font-medium underline underline-offset-2"
                                onClick={() =>
                                    router.post(workspaceRoutes.switch, {
                                        organization_id: workspace.id,
                                    })
                                }
                            >
                                Switch to {workspace.name}
                            </button>
                        )}
                    </div>
                </div>
            )}
        </li>
    );
}

/** A company's rounded-square mark, with an initials fallback. */
function Mark({ workspace }: { workspace: MyWorkspace }) {
    return (
        <div className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted ring-1 ring-border">
            {workspace.logo_url ? (
                <img
                    src={workspace.logo_url}
                    alt=""
                    className="size-full object-contain"
                />
            ) : (
                <span className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {workspace.initials}
                </span>
            )}
        </div>
    );
}

function formatJoined(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

Workspaces.layout = {
    breadcrumbs: [
        {
            title: 'Workspaces',
            href: workspaceRoutes.manage,
        },
    ],
};

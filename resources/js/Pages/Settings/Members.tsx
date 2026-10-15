import AppLayout from '@/layouts/app-layout';
import { FlashBanner } from '@/components/flash-banner';
import { SettingsShell } from '@/components/settings-shell';
import { Avatar } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageSharedProps } from '@/types';

interface MemberRow {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'member';
    joinedAt: string | null;
}

interface InvitationRow {
    id: string;
    email: string;
    status: 'sent' | 'expired';
    invitedAt: string | null;
}

type PageProps = {
    members: MemberRow[];
    invitations: InvitationRow[];
};

function formatJoined(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, { year: 'numeric', month: 'short' });
}

export default function Members() {
    const { members, invitations } = usePage<PageProps>().props;
    const { workspace, auth } = usePage<PageSharedProps>().props;
    const isOwner = workspace?.role === 'owner';
    const [inviting, setInviting] = useState(false);

    const inviteForm = useForm({ email: '' });

    const submitInvite = (e: React.FormEvent) => {
        e.preventDefault();
        inviteForm.post(route('workspaces.invite', workspace?.id), {
            onSuccess: () => {
                setInviting(false);
                inviteForm.reset();
            },
        });
    };

    const remove = (member: MemberRow) => {
        if (!window.confirm(`Remove ${member.name} from this workspace?`)) return;
        router.delete(route('workspaces.members.destroy', [workspace?.id, member.id]));
    };

    return (
        <AppLayout title="Settings" subtitle={workspace?.name} narrow>
            <SettingsShell
                title="Workspace members"
                description="Two roles only — owner and member. No granular permissions."
                actions={
                    <Button size="sm" onClick={() => setInviting(true)}>
                        Invite
                    </Button>
                }
            >
                <Head title="Members" />

            <FlashBanner className="break-all" />

            <div className="overflow-hidden rounded-xl border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Member</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="w-16" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {members.map((member) => (
                            <TableRow key={member.id}>
                                <TableCell>
                                    <div className="flex items-center gap-2.5">
                                        <span className={member.role === 'owner' ? '' : 'grayscale'}>
                                            <Avatar name={member.name} />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="font-medium">{member.name}</p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {member.email}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary" className="rounded-full bg-secondary text-muted-foreground">
                                        {member.role}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    Member since {formatJoined(member.joinedAt)}
                                </TableCell>
                                <TableCell>
                                    {isOwner && auth.user?.id !== member.id ? (
                                        <Button variant="ghost" size="sm" onClick={() => remove(member)}>
                                            Remove
                                        </Button>
                                    ) : null}
                                </TableCell>
                            </TableRow>
                        ))}
                        {invitations.map((invitation) => (
                            <TableRow key={invitation.id}>
                                <TableCell>
                                    <div className="flex items-center gap-2.5">
                                        <span className="opacity-50 grayscale">
                                            <Avatar name={invitation.email} />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="font-medium text-muted-foreground">
                                                {invitation.email}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary" className="rounded-full bg-secondary text-muted-foreground">
                                        member
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant="secondary"
                                        className={`rounded-full ${
                                            invitation.status === 'sent'
                                                ? 'bg-success/10 text-success'
                                                : 'bg-warning/10 text-warning'
                                        }`}
                                    >
                                        {invitation.status === 'sent' ? (
                                            <span className="size-1.5 rounded-full bg-current" />
                                        ) : null}
                                        {invitation.status === 'sent' ? 'Invitation sent' : 'Expired'}
                                    </Badge>
                                </TableCell>
                                <TableCell />
                            </TableRow>
                        ))}
                        {members.length === 0 && invitations.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={4} className="py-6 text-center text-muted-foreground">
                                    No members yet.
                                </TableCell>
                            </TableRow>
                        ) : null}
                    </TableBody>
                </Table>
            </div>

            <p className="mt-3 text-sm text-muted-foreground">
                Invitations are reserved for owners. Invitees join the workspace as <strong>member</strong>{' '}
                after accepting.
            </p>

            <Dialog open={inviting} onOpenChange={setInviting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Invite a member</DialogTitle>
                        <DialogDescription>
                            The role is always <strong>member</strong> — two roles only.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitInvite} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="invite-email">Email</Label>
                            <Input
                                id="invite-email"
                                type="email"
                                placeholder="name@example.com"
                                value={inviteForm.data.email}
                                onChange={(e) => inviteForm.setData('email', e.target.value)}
                                required
                                autoFocus
                            />
                            {inviteForm.errors.email ? (
                                <p className="text-sm text-destructive">{inviteForm.errors.email}</p>
                            ) : null}
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={inviteForm.processing}>
                                Invite
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            </SettingsShell>
        </AppLayout>
    );
}

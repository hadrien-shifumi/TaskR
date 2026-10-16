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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { MoreHorizontal, Send, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';
import { formatMonthYear } from '@/lib/format-date';

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
    return formatMonthYear(date);
}

const MENU_TRIGGER_CLASS =
    'grid size-6.5 place-items-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground';

export default function Members() {
    const { members, invitations } = usePage<PageProps>().props;
    const { workspace, auth } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
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
        if (!window.confirm(t('membersPage.removeConfirm', { name: member.name }))) return;
        router.delete(route('workspaces.members.destroy', [workspace?.id, member.id]));
    };

    const resend = (invitation: InvitationRow) => {
        router.post(route('workspaces.invitations.resend', [workspace?.id, invitation.id]));
    };

    const cancel = (invitation: InvitationRow) => {
        if (!window.confirm(t('membersPage.cancelConfirm', { email: invitation.email }))) return;
        router.delete(route('workspaces.invitations.destroy', [workspace?.id, invitation.id]));
    };

    return (
        <AppLayout title={t('settings.pageTitle')} subtitle={workspace?.name} narrow>
            <SettingsShell
                title={t('membersPage.title')}
                description={t('membersPage.description')}
                actions={
                    <Button size="sm" onClick={() => setInviting(true)}>
                        {t('membersPage.invite')}
                    </Button>
                }
            >
                <Head title={t('settings.sections.members')} />

            <FlashBanner className="break-all" />

            <div className="overflow-hidden rounded-xl border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('membersPage.columnMember')}</TableHead>
                            <TableHead>{t('membersPage.columnRole')}</TableHead>
                            <TableHead>{t('membersPage.columnStatus')}</TableHead>
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
                                        {t(`layout.roles.${member.role}`)}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {t('membersPage.memberSince', { date: formatJoined(member.joinedAt) })}
                                </TableCell>
                                <TableCell>
                                    {isOwner && auth.user?.id !== member.id ? (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                aria-label={`Actions for ${member.name}`}
                                                className={MENU_TRIGGER_CLASS}
                                            >
                                                <MoreHorizontal className="size-4" aria-hidden />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuGroup>
                                                    <DropdownMenuItem variant="destructive" onClick={() => remove(member)}>
                                                        <Trash2 aria-hidden />
                                                        {t('membersPage.removeFromWorkspace')}
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    ) : null}
                                </TableCell>
                            </TableRow>
                        ))}
                        {invitations.map((invitation) => (
                            <TableRow key={invitation.id} data-test={`invitation-row-${invitation.email}`}>
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
                                        {t('layout.roles.member')}
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
                                        {invitation.status === 'sent'
                                            ? t('membersPage.invitationSent')
                                            : t('membersPage.expired')}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    {isOwner ? (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                aria-label={t('membersPage.invitationAria', { email: invitation.email })}
                                                className={MENU_TRIGGER_CLASS}
                                            >
                                                <MoreHorizontal className="size-4" aria-hidden />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuGroup>
                                                    <DropdownMenuItem onClick={() => resend(invitation)}>
                                                        <Send aria-hidden />
                                                        {t('membersPage.resend')}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem variant="destructive" onClick={() => cancel(invitation)}>
                                                        <X aria-hidden />
                                                        {t('membersPage.cancelInvitation')}
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    ) : null}
                                </TableCell>
                            </TableRow>
                        ))}
                        {members.length === 0 && invitations.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={4} className="py-6 text-center text-muted-foreground">
                                    {t('membersPage.noMembers')}
                                </TableCell>
                            </TableRow>
                        ) : null}
                    </TableBody>
                </Table>
            </div>

            <p className="mt-3 text-sm text-muted-foreground">
                {t('membersPage.notePrefix')} <strong>{t('membersPage.memberWord')}</strong>{' '}
                {t('membersPage.noteSuffix')}
            </p>

            <Dialog open={inviting} onOpenChange={setInviting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('membersPage.inviteTitle')}</DialogTitle>
                        <DialogDescription>
                            {t('membersPage.inviteDescriptionPrefix')} <strong>{t('membersPage.memberWord')}</strong>{' '}
                            {t('membersPage.inviteDescriptionSuffix')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitInvite} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="invite-email">{t('auth.login.email')}</Label>
                            <Input
                                id="invite-email"
                                type="email"
                                placeholder="name@example.com"
                                value={inviteForm.data.email}
                                onChange={(e) => inviteForm.setData('email', e.target.value)}
                                required
                                autoFocus
                                aria-invalid={inviteForm.errors.email ? true : undefined}
                            />
                            {inviteForm.errors.email ? (
                                <p className="text-sm text-destructive">{inviteForm.errors.email}</p>
                            ) : null}
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={inviteForm.processing}>
                                {t('membersPage.invite')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            </SettingsShell>
        </AppLayout>
    );
}

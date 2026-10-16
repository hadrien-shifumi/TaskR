import AppLayout from '@/layouts/app-layout';
import { FlashBanner } from '@/components/flash-banner';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, MoreHorizontal, Plus, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatMonthYear } from '@/lib/format-date';

interface ProjectMemberRow {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'manager' | 'member';
    joinedAt: string | null;
}

interface AvailableMember {
    id: number;
    name: string;
    email: string;
}

type PageProps = {
    project: {
        id: string;
        name: string;
    };
    members: ProjectMemberRow[];
    availableMembers: AvailableMember[];
    canManage: boolean;
};

const MENU_TRIGGER_CLASS =
    'grid size-6.5 place-items-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground';

const ROLE_LABELS: Record<string, string> = {
    owner: 'Owner',
    manager: 'Manager',
    member: 'Member',
};

export default function Members() {
    const { project, members, availableMembers, canManage } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [onboarding, setOnboarding] = useState(false);

    const addForm = useForm({
        user_id: 0,
        role: 'member',
    });

    const submitAdd = (e: React.FormEvent) => {
        e.preventDefault();
        addForm.post(route('projects.members.store', project.id), {
            onSuccess: () => {
                setOnboarding(false);
                addForm.reset();
            },
        });
    };

    const remove = (member: ProjectMemberRow) => {
        if (!window.confirm(`Remove ${member.name} from the project?`)) return;
        router.delete(route('projects.members.destroy', [project.id, member.id]));
    };

    const updateRole = (member: ProjectMemberRow, role: string) => {
        router.patch(route('projects.members.update', [project.id, member.id]), {
            role,
        });
    };

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Back to projects"
                    onClick={() => router.get(route('projects.index'))}
                >
                    <ArrowLeft aria-hidden />
                </Button>
            }
            title={project.name}
            subtitle="Project members"
            actions={
                canManage ? (
                    <Button size="sm" onClick={() => setOnboarding(true)}>
                        <UserPlus className="size-3.5" aria-hidden />
                        Add member
                    </Button>
                ) : null
            }
        >
            <Head title={`Members — ${project.name}`} />

            <FlashBanner className="break-all" />

            <div className="overflow-hidden rounded-xl border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Member</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Joined</TableHead>
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
                                    <Badge
                                        variant="secondary"
                                        className="rounded-full bg-secondary text-muted-foreground"
                                    >
                                        {ROLE_LABELS[member.role] ?? member.role}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {member.joinedAt ? formatMonthYear(member.joinedAt) : '—'}
                                </TableCell>
                                <TableCell>
                                    {canManage && member.role !== 'owner' ? (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                aria-label={`Actions for ${member.name}`}
                                                className={MENU_TRIGGER_CLASS}
                                            >
                                                <MoreHorizontal className="size-4" aria-hidden />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuGroup>
                                                    <DropdownMenuItem
                                                        onClick={() => updateRole(member, 'manager')}
                                                    >
                                                        Promote to manager
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onClick={() => updateRole(member, 'member')}
                                                    >
                                                        Demote to member
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() => remove(member)}
                                                    >
                                                        <Trash2 aria-hidden />
                                                        Remove from project
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    ) : null}
                                </TableCell>
                            </TableRow>
                        ))}
                        {members.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={4} className="py-6 text-center text-muted-foreground">
                                    No members in this project yet.
                                </TableCell>
                            </TableRow>
                        ) : null}
                    </TableBody>
                </Table>
            </div>

            <Dialog open={onboarding} onOpenChange={setOnboarding}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add member to project</DialogTitle>
                        <DialogDescription>
                            Select a workspace member to add to the project roster.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitAdd} className="grid gap-4">
                        <div className="grid gap-2">
                            <label className="text-sm font-medium" htmlFor="member-select">
                                Member
                            </label>
                            <Select
                                value={String(addForm.data.user_id || '')}
                                onValueChange={(value) => addForm.setData('user_id', Number(value))}
                            >
                                <SelectTrigger id="member-select">
                                    <SelectValue placeholder="Select a member" />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableMembers.map((member) => (
                                        <SelectItem key={member.id} value={String(member.id)}>
                                            {member.name} ({member.email})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {addForm.errors.user_id ? (
                                <p className="text-sm text-destructive">{addForm.errors.user_id}</p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <label className="text-sm font-medium" htmlFor="role-select">
                                Role
                            </label>
                            <Select
                                value={addForm.data.role}
                                onValueChange={(value) => addForm.setData('role', value)}
                            >
                                <SelectTrigger id="role-select">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="member">Member</SelectItem>
                                    <SelectItem value="manager">Manager</SelectItem>
                                </SelectContent>
                            </Select>
                            {addForm.errors.role ? (
                                <p className="text-sm text-destructive">{addForm.errors.role}</p>
                            ) : null}
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={addForm.processing || !addForm.data.user_id}>
                                <Plus aria-hidden />
                                Add member
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

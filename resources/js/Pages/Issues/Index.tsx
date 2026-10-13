import AppLayout from '@/layouts/app-layout';
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
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface UserRef {
    id: string;
    name: string;
}

interface IssueRow {
    id: string;
    title: string;
    project: { id: string; name: string };
    status: { id: string; name: string; category: string };
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    assignees: UserRef[];
    labels: { id: string; name: string; color: string }[];
}

type PageProps = {
    issues: IssueRow[];
    filters: Record<string, string>;
    projects: { id: string; name: string }[];
    statuses: { id: string; name: string; category: string }[];
    labels: { id: string; name: string; color: string }[];
    members: UserRef[];
};

const PRIORITIES = ['none', 'low', 'high', 'urgent'];
const CATEGORIES = ['backlog', 'in_progress', 'done', 'cancelled'];

export default function Index() {
    const { issues, filters, projects, statuses, labels, members } = usePage<PageProps>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const [creating, setCreating] = useState(false);
    const [creatingLabel, setCreatingLabel] = useState(false);

    const createForm = useForm({
        project_id: projects[0]?.id ?? '',
        title: '',
        description: '',
        status_id: '',
        priority: 'none',
        due_date: '',
    });
    const labelForm = useForm({ name: '', color: '' });

    const selectedProject = projects.find((p) => p.id === filters.project);
    const selectedStatus = statuses.find((s) => s.id === filters.status);
    const selectedLabel = labels.find((l) => l.id === filters.label);
    const selectedMember = members.find((m) => m.id === filters.assignee);
    const createStatusId = createForm.data.status_id || statuses[0]?.id || '';

    const setFilter = (key: string, value: string) => {
        const next = { ...filters, [key]: value === 'all' ? '' : value };
        router.get(route('issues.index'), next, { preserveState: true });
    };

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('issues.store'), {
            onSuccess: () => {
                setCreating(false);
                createForm.reset();
            },
        });
    };

    const submitLabel = (e: React.FormEvent) => {
        e.preventDefault();
        labelForm.post(route('labels.store'), {
            onSuccess: () => {
                setCreatingLabel(false);
                labelForm.reset();
            },
        });
    };

    return (
        <AppLayout title="Issues">
            <Head title="Issues" />

            {flash?.status ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm">{flash.status}</p>
            ) : null}

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Select value={filters.project ?? 'all'} onValueChange={(v) => v && setFilter('project', v)}>
                    <SelectTrigger className="w-44">
                        <SelectValue>{selectedProject?.name ?? 'All projects'}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All projects</SelectItem>
                        {projects.map((p) => (
                            <SelectItem key={p.id} value={p.id}>
                                {p.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={filters.status ?? 'all'} onValueChange={(v) => v && setFilter('status', v)}>
                    <SelectTrigger className="w-44">
                        <SelectValue>{selectedStatus?.name ?? 'All statuses'}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All statuses</SelectItem>
                        {statuses.map((s) => (
                            <SelectItem key={s.id} value={s.id}>
                                {s.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={filters.category ?? 'all'} onValueChange={(v) => v && setFilter('category', v)}>
                    <SelectTrigger className="w-44">
                        <SelectValue>
                            {filters.category && filters.category !== 'all' ? filters.category : 'All categories'}
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All categories</SelectItem>
                        {CATEGORIES.map((c) => (
                            <SelectItem key={c} value={c}>
                                {c}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={filters.priority ?? 'all'} onValueChange={(v) => v && setFilter('priority', v)}>
                    <SelectTrigger className="w-36">
                        <SelectValue>
                            {filters.priority && filters.priority !== 'all' ? filters.priority : 'Any priority'}
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any priority</SelectItem>
                        {PRIORITIES.map((p) => (
                            <SelectItem key={p} value={p}>
                                {p}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={filters.label ?? 'all'} onValueChange={(v) => v && setFilter('label', v)}>
                    <SelectTrigger className="w-36">
                        <SelectValue>{selectedLabel?.name ?? 'Any label'}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any label</SelectItem>
                        {labels.map((l) => (
                            <SelectItem key={l.id} value={l.id}>
                                {l.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={filters.assignee ?? 'all'} onValueChange={(v) => v && setFilter('assignee', v)}>
                    <SelectTrigger className="w-40">
                        <SelectValue>{selectedMember?.name ?? 'Any assignee'}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any assignee</SelectItem>
                        {members.map((m) => (
                            <SelectItem key={m.id} value={m.id}>
                                {m.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button
                    variant={filters.overdue === '1' ? 'default' : 'outline'}
                    size="sm"
                    onClick={() => setFilter('overdue', filters.overdue === '1' ? '' : '1')}
                >
                    Overdue
                </Button>
                <div className="ml-auto flex gap-2">
                    <Button variant="outline" size="sm" onClick={() => setCreatingLabel(true)}>
                        New label
                    </Button>
                    <Button size="sm" onClick={() => setCreating(true)}>
                        New issue
                    </Button>
                </div>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead>Project</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Priority</TableHead>
                        <TableHead>Assignees</TableHead>
                        <TableHead>Labels</TableHead>
                        <TableHead>Due</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {issues.map((issue) => (
                        <TableRow key={issue.id}>
                            <TableCell>
                                <Link href={route('issues.show', issue.id)} className="font-medium hover:underline">
                                    {issue.title}
                                </Link>
                            </TableCell>
                            <TableCell className="text-muted-foreground">{issue.project.name}</TableCell>
                            <TableCell>
                                <Badge variant="outline">{issue.status.name}</Badge>
                            </TableCell>
                            <TableCell className="text-muted-foreground">{issue.priority}</TableCell>
                            <TableCell className="text-muted-foreground">
                                {issue.assignees.map((a) => a.name).join(', ') || '—'}
                            </TableCell>
                            <TableCell>
                                <div className="flex gap-1">
                                    {issue.labels.map((l) => (
                                        <Badge key={l.id} variant="secondary">
                                            {l.name}
                                        </Badge>
                                    ))}
                                </div>
                            </TableCell>
                            <TableCell className={issue.overdue ? 'font-medium text-destructive' : 'text-muted-foreground'}>
                                {issue.dueDate ? new Date(issue.dueDate).toLocaleDateString() : '—'}
                                {issue.overdue ? ' (overdue)' : ''}
                            </TableCell>
                        </TableRow>
                    ))}
                    {issues.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={7} className="py-6 text-center text-muted-foreground">
                                No issues match these filters.
                            </TableCell>
                        </TableRow>
                    ) : null}
                </TableBody>
            </Table>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>New issue</DialogTitle>
                        <DialogDescription>Title is the only required field.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="issue-title">Title</Label>
                            <Input
                                id="issue-title"
                                value={createForm.data.title}
                                onChange={(e) => createForm.setData('title', e.target.value)}
                                required
                            />
                            {createForm.errors.title ? (
                                <p className="text-sm text-destructive">{createForm.errors.title}</p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="issue-description">Description</Label>
                            <Textarea
                                id="issue-description"
                                value={createForm.data.description}
                                onChange={(e) => createForm.setData('description', e.target.value)}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label>Project</Label>
                                <Select
                                    value={createForm.data.project_id}
                                    onValueChange={(v) => {
                                        if (v) createForm.setData('project_id', v);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue>
                                            {projects.find((p) => p.id === createForm.data.project_id)?.name ?? 'Project'}
                                        </SelectValue>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {projects.map((p) => (
                                            <SelectItem key={p.id} value={p.id}>
                                                {p.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {createForm.errors.project_id ? (
                                    <p className="text-sm text-destructive">{createForm.errors.project_id}</p>
                                ) : null}
                            </div>
                            <div className="grid gap-2">
                                <Label>Status</Label>
                                <Select
                                    value={createStatusId}
                                    onValueChange={(v) => {
                                        if (v) createForm.setData('status_id', v);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue>
                                            {statuses.find((s) => s.id === createStatusId)?.name ?? 'Status'}
                                        </SelectValue>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((s) => (
                                            <SelectItem key={s.id} value={s.id}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label>Priority</Label>
                                <Select
                                    value={createForm.data.priority}
                                    onValueChange={(v) => {
                                        if (v) createForm.setData('priority', v);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {PRIORITIES.map((p) => (
                                            <SelectItem key={p} value={p}>
                                                {p}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="issue-due">Due date</Label>
                                <Input
                                    id="issue-due"
                                    type="date"
                                    value={createForm.data.due_date}
                                    onChange={(e) => createForm.setData('due_date', e.target.value)}
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={createForm.processing}>
                                Create
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={creatingLabel} onOpenChange={setCreatingLabel}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New label</DialogTitle>
                        <DialogDescription>Workspace-wide labels, freely attachable.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitLabel} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="label-name">Name</Label>
                            <Input
                                id="label-name"
                                value={labelForm.data.name}
                                onChange={(e) => labelForm.setData('name', e.target.value)}
                                required
                            />
                            {labelForm.errors.name ? (
                                <p className="text-sm text-destructive">{labelForm.errors.name}</p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="label-color">Color</Label>
                            <Input
                                id="label-color"
                                value={labelForm.data.color}
                                onChange={(e) => labelForm.setData('color', e.target.value)}
                                placeholder="gray"
                            />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={labelForm.processing}>
                                Create
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

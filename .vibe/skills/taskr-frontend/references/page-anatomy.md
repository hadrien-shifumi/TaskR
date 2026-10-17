# Page Anatomy

Canonical page skeleton distilled from `Pages/Issues/Index.tsx` and `Pages/Issues/Show.tsx`.

## Imports

Ordered: layout, ui components (alphabetical by path), inertia hooks, React. `@/` alias everywhere except third parties.

```tsx
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
```

Note: when a page needs both the ui `Label` and a domain "label" concept, alias the field component (`import { Label as LabelField } from '@/components/ui/label';`).

## Types then constants

```tsx
interface UserRef { id: string; name: string; }

type PageProps = {
    issues: IssueRow[];        // mirrors responder payload, camelCase
    filters: Record<string, string>;
    projects: { id: string; name: string }[];
};

const PRIORITIES = ['none', 'low', 'high', 'urgent'];
const CATEGORIES = ['backlog', 'in_progress', 'done', 'cancelled'];
```

## Component shell

```tsx
export default function Index() {
    const { issues, filters, projects } = usePage<PageProps>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const [creating, setCreating] = useState(false); // dialog open flags

    const createForm = useForm({ project_id: projects[0]?.id ?? '', title: '', description: '' });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('issues.store'), {
            onSuccess: () => {
                setCreating(false);
                createForm.reset();
            },
        });
    };

    return (
        <AppLayout title="Issues">
            <Head title="Issues" />

            {flash?.status ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm">{flash.status}</p>
            ) : null}
            {/* toolbar: Select filters + Overdue toggle + action Buttons (ml-auto) */}
            {/* Table / cards */}
            {/* Dialog forms */}
        </AppLayout>
    );
}
```

## Filter select (controlled by URL, not local state)

```tsx
const selectedProject = projects.find((p) => p.id === filters.project);

const setFilter = (key: string, value: string) => {
    const next = { ...filters, [key]: value === 'all' ? '' : value };
    router.get(route('issues.index'), next, { preserveState: true });
};

<Select value={filters.project ?? 'all'} onValueChange={(v) => v && setFilter('project', v)}>
    <SelectTrigger className="w-44">
        <SelectValue>{selectedProject?.name ?? 'All projects'}</SelectValue>
    </SelectTrigger>
    <SelectContent>
        <SelectItem value="all">All projects</SelectItem>
        {projects.map((p) => (
            <SelectItem key={p.id} value={p.id}>{p.name}</SelectItem>
        ))}
    </SelectContent>
</Select>
```

## Toggle lists (assignees, labels)

Optimistic-free, server-authoritative: read current ids from props, compute next array, POST the whole array.

```tsx
const toggleAssignee = (userId: string) => {
    const current = issue.assignees.map((a) => a.id);
    const next = current.includes(userId)
        ? current.filter((id) => id !== userId)
        : [...current, userId];
    router.post(route('issues.assignees', issue.id), { assignees: next });
};
```

## Edit form seeded from props

```tsx
const editForm = useForm({
    title: issue.title,
    description: issue.description ?? '',
    due_date: issue.dueDate ? issue.dueDate.slice(0, 10) : '', // ISO -> yyyy-mm-dd for date inputs
});

editForm.patch(route('issues.update', issue.id), { onSuccess: () => setEditing(false) });
```

## Destructive action

```tsx
const remove = () => {
    if (!window.confirm(`Delete issue "${issue.title}"?`)) return;
    router.delete(route('issues.destroy', issue.id));
};
```

## Table rows and empty state

```tsx
<Table>
    <TableHeader>
        <TableRow>
            <TableHead>Title</TableHead>
            <TableHead>Project</TableHead>
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
            </TableRow>
        ))}
        {issues.length === 0 ? (
            <TableRow>
                <TableCell colSpan={2} className="py-6 text-center text-muted-foreground">
                    No issues match these filters.
                </TableCell>
            </TableRow>
        ) : null}
    </TableBody>
</Table>
```

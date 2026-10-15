import { FormDialog } from '@/components/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useForm } from '@inertiajs/react';

type ProjectOption = { id: string; name: string };

type QuickCreateIssueDialogProps = {
    open: boolean;
    onClose: () => void;
    projects: ProjectOption[];
};

/**
 * Minimal issue creation (home pagebar, maquette home.html): a title and a
 * project are enough — the default status and the none priority are applied
 * server-side, everything else stays editable after creation.
 */
export function QuickCreateIssueDialog({ open, onClose, projects }: QuickCreateIssueDialogProps) {
    const form = useForm({
        title: '',
        project_id: projects[0]?.id ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('issues.store'), {
            onSuccess: () => {
                onClose();
                form.reset();
            },
        });
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title="New issue"
            description="A title is enough — default status and priority are applied."
            submitLabel="Create issue"
            processing={form.processing}
            onSubmit={submit}
        >
            <div className="grid gap-2">
                <Label htmlFor="quick-issue-title">Title</Label>
                <Input
                    id="quick-issue-title"
                    placeholder="Describe the task in one sentence"
                    aria-required="true"
                    autoFocus
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                />
                {form.errors.title ? (
                    <p data-test="quick-title-error" className="text-sm text-destructive">
                        {form.errors.title}
                    </p>
                ) : null}
            </div>
            <div className="grid gap-2">
                <Label>Project</Label>
                <Select
                    value={form.data.project_id}
                    onValueChange={(v) => {
                        if (v) form.setData('project_id', v);
                    }}
                >
                    <SelectTrigger aria-label="Project">
                        <SelectValue>
                            {projects.find((p) => p.id === form.data.project_id)?.name ?? 'Project'}
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
                {form.errors.project_id ? (
                    <p className="text-sm text-destructive">{form.errors.project_id}</p>
                ) : null}
            </div>
            <p className="text-sm text-muted-foreground">
                All other fields (description, labels, assignees, due date) stay optional and
                editable after creation.
            </p>
        </FormDialog>
    );
}

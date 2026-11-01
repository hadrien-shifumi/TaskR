/**
 * Story conventions: colocated `*.stories.tsx`, CSF3, title
 * `Components/<Name>` (docs/design-system/components.md), Tailwind design
 * tokens only, 4-space indent + single quotes + semicolons.
 *
 * FormDialog is a controlled shell (`open`/`onOpenChange`), so the stories
 * mount a small harness holding the state — exactly like a page would.
 */
import type { Meta, StoryObj } from '@storybook/react';
import { useState } from 'react';

import { FormDialog } from '@/components/form-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type FormDialogHarnessProps = {
    defaultOpen?: boolean;
    processing?: boolean;
    withFieldError?: boolean;
};

function FormDialogHarness({ defaultOpen = false, processing = false, withFieldError = false }: FormDialogHarnessProps) {
    const [open, setOpen] = useState(defaultOpen);
    const [submitted, setSubmitted] = useState(false);

    return (
        <div className="grid gap-3">
            <div className="flex items-center gap-3">
                <Button onClick={() => setOpen(true)}>New label</Button>
                {submitted ? (
                    <span className="text-sm text-muted-foreground">Submitted — the page would refresh here.</span>
                ) : null}
            </div>
            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title="New label"
                description="Labels group issues across every project of the workspace."
                submitLabel="Create label"
                processing={processing}
                onSubmit={(event) => {
                    event.preventDefault();
                    setSubmitted(true);
                    setOpen(false);
                }}
            >
                <div className="grid gap-2">
                    <Label htmlFor="story-label-name">Name</Label>
                    <Input id="story-label-name" placeholder="Bug" required />
                    {withFieldError ? (
                        <p className="text-sm text-destructive">This name is already taken in this workspace.</p>
                    ) : null}
                </div>
            </FormDialog>
        </div>
    );
}

const meta = {
    title: 'Components/FormDialog',
    component: FormDialog,
    tags: ['autodocs'],
    args: {
        open: false,
        onOpenChange: () => {},
        title: 'New label',
        description: 'Labels group issues across every project of the workspace.',
        submitLabel: 'Create label',
        onSubmit: () => {},
        children: null,
    },
    argTypes: {
        open: { control: false, description: 'Controlled by the page — the stories use a harness.' },
        onOpenChange: { control: false },
        onSubmit: { control: false },
        children: { control: false },
        processing: { control: 'boolean', description: 'Disables the submit button while the form posts.' },
    },
} satisfies Meta<typeof FormDialog>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Interactive: Story = {
    name: 'Interactive form',
    render: () => <FormDialogHarness />,
};

export const FieldError: Story = {
    name: 'Field error in children',
    render: () => <FormDialogHarness defaultOpen withFieldError />,
};

export const Processing: Story = {
    name: 'Processing (submit disabled)',
    render: () => <FormDialogHarness defaultOpen processing />,
};

export const PinnedHeaderAndFooter: Story = {
    name: 'Pinned header and footer, scrollable body',
    render: () => {
        const [open, setOpen] = useState(true);

        return (
            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title="New issue"
                description="Title is the only required field."
                submitLabel="Create issue"
                contentClassName="max-h-[70vh] sm:max-w-[min(1400px,calc(100vw-2rem))]"
                bodyClassName="grid gap-6 md:grid-cols-[minmax(0,1fr)_minmax(280px,360px)]"
                scrollableBody
                onSubmit={() => setOpen(false)}
            >
                <div className="grid min-h-0 gap-2">
                    <Label htmlFor="story-issue-title">Title</Label>
                    <Input id="story-issue-title" placeholder="Describe the task in one sentence" required />
                    <Textarea id="story-issue-body" rows={24} placeholder={'## Context\n\n…'} />
                </div>
                <div className="grid auto-rows-min content-start gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="story-issue-status">Status</Label>
                        <Input id="story-issue-status" defaultValue="Backlog" />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="story-issue-priority">Priority</Label>
                        <Input id="story-issue-priority" defaultValue="None" />
                    </div>
                </div>
            </FormDialog>
        );
    },
};

export const ScrollableLongForm: Story = {
    name: 'Scrollable long form',
    render: () => {
        const [open, setOpen] = useState(true);

        return (
            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title="New template"
                description="Templates pre-fill new issues with a description, a priority and labels."
                submitLabel="Create template"
                contentClassName="max-h-[70vh] overflow-y-auto sm:max-w-2xl"
                onSubmit={() => setOpen(false)}
            >
                <div className="grid gap-2">
                    <Label htmlFor="story-template-name">Name</Label>
                    <Input id="story-template-name" placeholder="Bug report" required />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="story-template-body">Body</Label>
                    <Textarea
                        id="story-template-body"
                        rows={12}
                        placeholder={'## Steps to reproduce\n\n1. …\n2. …\n\n**Expected:** …'}
                    />
                </div>
            </FormDialog>
        );
    },
};
